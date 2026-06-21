<?php

namespace App\Http\Controllers\Evidence;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Evidence\Concerns\ResolvesEvidenceAddonRules;
use App\Models\EvidenceInput;
use App\Models\Facility;
use App\Models\FacilitySetting;
use App\Models\OfficialPrice;
use App\Models\SubsidyBudget;
use App\Models\SubsidyInput;
use App\Models\TreatmentImprovementRule;
use App\Services\Evidence\SubsidyMasterSyncService;
use App\Services\Evidence\MonthlyActualsCalculator;
use App\Support\FiscalYear;
use App\Support\FacilityTypeCodeCatalog;
use App\Support\EvidenceInputCodeCatalog;
use App\Support\OfficialPriceItemCodeCatalog;
use App\Support\SubsidyCodes;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


/**
 * 予算入力画面の表示・保存・月次補助金計算を統括する Controller。
 *
 * このクラスの責務:
 * - 画面表示に必要な「入力値」「設定値」「計算結果」を組み立てる
 * - ユーザー入力を正規化して、関連テーブルへ一貫性を保って保存する
 * - 人数入力 + addon設定 + 単価マスタから、月次の補助金金額を算出する
 *
 * 読み方のおすすめ:
 * 1. `index()` で表示データの流れを掴む
 * 2. `update()` で保存トランザクションの流れを掴む
 * 3. `buildMonthlyTotalsForSync()` で計算ロジックの中核を追う
 * 4. 下部の小さな補助メソッドで細部を確認する
 *
 * データ形状メモ（ここだけ見れば追える版）:
 * - `$values[input_code][year_month] = '人数(文字列)'`
 * - `$addonValues[ui_code][year_month] = ['is_selected' => bool, 'input_value' => ?string]`
 * - `$inputs[input_code][year_month] = int|string|null`（リクエスト生値）
 * - `$addons[ui_code][year_month] = ['is_selected' => bool, 'input_value' => ?int]`（正規化後）
 * - `$monthlyTotalsForSync[subsidy_code][year_month] = float`
 */
class BudgetsInputsController extends Controller
{
    use ResolvesEvidenceAddonRules;

    public function __construct(
        private readonly MonthlyActualsCalculator $monthlyActualsCalculator
    ) {}

    /**
     * 予算の根拠入力（年齢別人数・年度設定・区分ルール）を扱う。
     * 入力値から月次合計を計算し、画面表示用データを返す。
     *
     * 主な流れ:
     * 1. 対象施設・年度を解決
     * 2. 人数入力（evidence_inputs budget scope）を取得
     * 3. addon 入力を整形して取得
     * 4. 単価マスタと入力値から月次金額を計算
     * 5. 画面描画に必要な配列を view へ渡す
     */
    public function index(Request $request): View
    {
        $facilityIdParam = $request->query('facility_id');
        $rows = EvidenceInputCodeCatalog::rows();

        $fiscalYear = (int)($request->query('fiscal_year') ?: FiscalYear::current());
        $facilities = Facility::orderBy('id')->get();
        $facility = $facilityIdParam
            ? $facilities->firstWhere('id', (int) $facilityIdParam)
            : $facilities->first();
        $facilityId = $facility?->id;

        if (!$facility) {
            return view('evidence.budgets_inputs.index', [
                'facility' => null,
                'facilityId' => null,
                'facilities' => $facilities,
                'fiscalYear' => $fiscalYear,
                'months' => [],
                'rows' => $rows,
                'values' => [],
                'addonValues' => [],
                'ruleInput' => null,
            ]);
        }

        $months = FiscalYear::months($fiscalYear);
        $annualSetting = $this->resolveAnnualSetting($facility, $fiscalYear);
        $rule = TreatmentImprovementRule::resolveForFacilityAndFiscalYear($facilityId, $fiscalYear);

        // 加算メニューをこの施設の種別で出し分ける（こども園は登録加算のみ／保育園は全件）。
        // メニュー構築(buildAddonValues 等)より前に設定する。種別未対応なら null＝全件。
        $this->useEvidenceFacilityTypeCode(
            $this->resolveFacilityTypeCode((string) ($annualSetting['facility_type'] ?? ''))
        );

        // 加算の入力欄をBladeで動的に並べるための「材料」を用意する（実績画面と同じ手順）。
        // なぜ：見込でもDB登録ずみの全加算を出すため（旧実装は5つ決め打ちだった）。
        // 並べる順序や施設種別フィルタは上の useEvidenceFacilityTypeCode より後に呼ぶことで効く。
        $checkboxAddonDefinitions = $this->checkboxAddonDefinitions();                                     // チェック型だけ
        $selectAddonDefinitions = $this->evidenceSelectAddonDefinitions();                                 // 選択型だけ
        $unifiedAddonDefinitions = $this->buildUnifiedAddonDefinitions($checkboxAddonDefinitions, $selectAddonDefinitions); // 2種を表示順に統合

        // evidence_inputs（budgetスコープ）から該当施設・年月・コードのレコードをまとめて取ってくる
        $records = EvidenceInput::query()
            ->where('input_scope', 'budget')
            ->where('facility_id', $facilityId)
            ->whereIn('year_month', $months)
            ->whereIn('input_code', array_keys($rows))
            ->get();

        /** @var array<string, array<string, string>> $values */
        $values = [];
        // values[input_code（園児の年齢）][year_month] = value（園児数） の形で配列に格納する
        // 年齢別×月別の園児数を簡単に参照できるようにするため
        foreach ($records as $r) {
            $values[$r->input_code][$r->year_month] = (string)((int)$r->value);
        }
        // buildaddonValues() の戻り値はuiCode（画面上の項目コード）と年月をキーにして、選択状態と入力値を格納した配列になる。
        /** @var array<string, array<string, array{is_selected: bool, input_value: ?string}>> $addonValues */
        $addonValues = $this->buildAddonValues($facilityId, $fiscalYear, $months);

        // --- ここから計算（人数×単価） ---
        $errorsCalc = [];
        // $months の各要素をキーにして、値を全部 0.0 で初期化した連想配列を作っている。
        // これが月別の合計金額を格納する配列になる。
        $monthlyTotals = array_fill_keys($months, 0.0);
        $monthlyTotalsTi12 = array_fill_keys($months, 0.0);
        $monthlyAge4 = array_fill_keys($months, 0.0);
        $monthlyAge4Ti12 = array_fill_keys($months, 0.0);
        $monthlyAge3 = array_fill_keys($months, 0.0);
        $monthlyAge3Ti12 = array_fill_keys($months, 0.0);
        $monthlyAge1 = array_fill_keys($months, 0.0);
        $monthlyAge1Ti12 = array_fill_keys($months, 0.0);
        $monthlyTeamCare = array_fill_keys($months, 0.0);
        $monthlyTeamCareTi12 = array_fill_keys($months, 0.0);
        // 認定別定員：年度設定があればそれ、無ければ施設デフォルト（保育所は null）。
        // 表示欄・保存と同じ resolveAnnualSetting 由来にそろえ、保存した年度値がプレビュー計算にも反映されるようにする。
        $capacityNursery = $annualSetting['capacity_nursery'];
        $capacityKindergarten = $annualSetting['capacity_kindergarten'];

        $regionCode = $annualSetting['region_code'];
        $capacity = $annualSetting['capacity'];
        $facilityType = $annualSetting['facility_type'];
        $category1Percent = (float)($rule?->category_1_percent ?? 0);
        $category2Percent = (float)($rule?->category_2_percent ?? 0);

        // $facilityType（こども園）がKeyで、$facilitytypecode（例: "KODOMOEN"）がValueのマッピングを取得する。
        $facilityTypeCode = $this->resolveFacilityTypeCode($facilityType);

        // データがない場合のエラー対応
        if (!$regionCode) {
            $errorsCalc[] = "地域区分（region_code）が未設定です。施設設定で入力してください（対象年度: {$fiscalYear}）。";
        }
        if ($capacity === null) {
            $errorsCalc[] = "定員（capacity）が未設定です。施設設定で入力してください（対象年度: {$fiscalYear}）。";
        }
        if (!$facilityTypeCode) {
            $errorsCalc[] = '施設種別が未対応のため、単価計算できません。';
        }

        $unitPrices = []; // age_code => value
        $class12AddonYenByAge = []; // age_code => value
        $class12AddonRateCByAge = []; // age_code => value
        // 戻り値は、$resolved[$usageKey][$componentKey] = $itemCodeになる
        $class12ItemCodesByComponent = OfficialPriceItemCodeCatalog::class12ItemCodesByComponent();
        $class12ItemCodes = array_values($class12ItemCodesByComponent);
        /** @var EloquentCollection<int, OfficialPrice>|null $officialPrices */
        $officialPrices = null;

        // 見込は標準/短を分けない＝単層の児童数を「全部standard」に組み立てて計算機へ渡す
        // （計算機は基本分単価を inputsByDuration から計算するため）。
        $inputsByDuration = [];
        foreach ($values as $code => $byYm) {
            foreach ($byYm as $ym => $n) {
                $inputsByDuration[$code]['standard'][$ym] = $n;
            }
        }

        // データがない場合は、単価の取得に進む。単価は official_prices テーブルから取ってくる。
        if (empty($errorsCalc)) {
            $officialPrices = $this->monthlyActualsCalculator->queryOfficialPrices(
                $fiscalYear,
                (string) $regionCode,
                $facilityTypeCode,
                (int) $capacity,
                $capacityNursery,
                $capacityKindergarten
            );

            // 取ってきたレコードを age_code をキー、value を値とする連想配列に変換する。
            // これで年齢別の単価が参照しやすくなる。
            $class12ComponentsByAge = $this->monthlyActualsCalculator->buildPriceComponentsByAge(
                $officialPrices,
                $class12ItemCodes
            );
            foreach ($class12ComponentsByAge as $ageCode => $components) {
                if (array_key_exists('basic', $components)) {
                    $unitPrices[$ageCode] = (float) $components['basic'];
                }
                if (array_key_exists('ti', $components)) {
                    $class12AddonYenByAge[$ageCode] = (float) $components['ti'];
                }
                if (array_key_exists('c', $components)) {
                    $class12AddonRateCByAge[$ageCode] = (float) $components['c'];
                }
            }

            $errorsCalc = array_merge($errorsCalc, $this->monthlyActualsCalculator->buildMissingAgeErrors($unitPrices, 'official_prices'));
            $errorsCalc = array_merge(
                $errorsCalc,
                $this->monthlyActualsCalculator->buildMissingAgeErrors(
                    $class12AddonYenByAge,
                    $class12ItemCodesByComponent['ti']
                )
            );
            $errorsCalc = array_merge(
                $errorsCalc,
                $this->monthlyActualsCalculator->buildMissingAgeErrors(
                    $class12AddonRateCByAge,
                    $class12ItemCodesByComponent['c']
                )
            );
        }
        $monthlyTotalsForView = $this->monthlyActualsCalculator->buildMonthlyTotalsForSync(
            $fiscalYear,
            $months,
            $regionCode,
            (int) ($capacity ?? 0),
            (string) ($facilityType ?? ''),
            $category1Percent,
            $category2Percent,
            $values,
            $addonValues,
            $officialPrices,
            $inputsByDuration,
            (int) ($rule?->category_3a ?? 0),
            (int) ($rule?->category_3b ?? 0),
            (int) ($capacity ?? 0),
            false,
            $capacityNursery,
            $capacityKindergarten
        );
        if ($monthlyTotalsForView !== null) {
            $monthlyTotals = $monthlyTotalsForView[SubsidyCodes::BASIC_UNIT_PRICE] ?? $monthlyTotals;
            $monthlyTotalsTi12 = $monthlyTotalsForView[SubsidyCodes::BASIC_UNIT_PRICE_TI12] ?? $monthlyTotalsTi12;
            $monthlyTeamCare = $monthlyTotalsForView[SubsidyCodes::TEAM_CARE] ?? $monthlyTeamCare;
            $monthlyTeamCareTi12 = $monthlyTotalsForView[SubsidyCodes::TEAM_CARE_TI12] ?? $monthlyTeamCareTi12;
            $monthlyAge4 = $monthlyTotalsForView[SubsidyCodes::AGE4] ?? $monthlyAge4;
            $monthlyAge4Ti12 = $monthlyTotalsForView[SubsidyCodes::AGE4_TI12] ?? $monthlyAge4Ti12;
            $monthlyAge3 = $monthlyTotalsForView[SubsidyCodes::AGE3] ?? $monthlyAge3;
            $monthlyAge3Ti12 = $monthlyTotalsForView[SubsidyCodes::AGE3_TI12] ?? $monthlyAge3Ti12;
            $monthlyAge1 = $monthlyTotalsForView[SubsidyCodes::AGE1] ?? $monthlyAge1;
            $monthlyAge1Ti12 = $monthlyTotalsForView[SubsidyCodes::AGE1_TI12] ?? $monthlyAge1Ti12;
        }
        $monthlyCat3 = $monthlyTotalsForView[SubsidyCodes::TREATMENT_IMPROVEMENT_CAT3] ?? array_fill_keys($months, 0.0);
        $monthlyFacilityCapability = $monthlyTotalsForView[SubsidyCodes::FACILITY_CAPABILITY_STRENGTHENING] ?? array_fill_keys($months, 0.0);

        return view('evidence.budgets_inputs.index', [
            'facility' => $facility,
            'facilityId' => $facilityId,
            'facilities' => $facilities,
            'fiscalYear' => $fiscalYear,
            'months' => $months,
            'rows' => $rows,
            'values' => $values,
            'addonValues' => $addonValues,
            'annualInput' => $annualSetting,
            'ruleInput' => [
                'category_1_percent' => $rule?->category_1_percent,
                'category_2_percent' => $rule?->category_2_percent,
                'category_3a' => $rule?->category_3a,
                'category_3b' => $rule?->category_3b,
            ],
            'calcErrors' => $errorsCalc,
            'monthlyTotals' => $monthlyTotals,
            'monthlyTotalsTi12' => $monthlyTotalsTi12,
            'monthlyTeamCare' => $monthlyTeamCare,
            'monthlyTeamCareTi12' => $monthlyTeamCareTi12,
            'monthlyAge4' => $monthlyAge4,
            'monthlyAge4Ti12' => $monthlyAge4Ti12,
            'monthlyAge3' => $monthlyAge3,
            'monthlyAge3Ti12' => $monthlyAge3Ti12,
            'monthlyAge1' => $monthlyAge1,
            'monthlyAge1Ti12' => $monthlyAge1Ti12,
            'monthlyCat3' => $monthlyCat3,
            'monthlyFacilityCapability' => $monthlyFacilityCapability,
            'regionCode' => $regionCode,
            'unifiedAddonDefinitions' => $unifiedAddonDefinitions,
        ]);
    }

    /**
     * 予算入力の保存エンドポイント。
     *
     * 目的:
     * - フォーム入力（人数・addon・年度設定）を正規化して保存する
     * - 保存内容と同じ入力条件で月次金額を再計算し、subsidy_budgets に同期する
     *
     * 主な副作用:
     * - `facility_settings` 更新
     * - `treatment_improvement_rules` 更新
     * - `evidence_inputs`（budget scope）再作成
     * - `subsidy_inputs`（budget scope）upsert
     * - `subsidy_budgets` upsert
     *
     * すべて DB::transaction 内で処理し、途中失敗時はロールバックする。
     */
    public function update(Request $request)
    {
        // バリデーションルールを定義。
        // facility_idとfiscal_yearは必須で整数、facility_idはfacilitiesテーブルのidと一致する必要がある。
        // annualは必須で配列、annual.region_codeは文字列で最大50文字、
        // annual.capacityは整数で0以上、annual.facility_typeは文字列で最大255文字。
        // ruleは必須で配列、rule.category_1_percentは整数で2以上12以下、
        // rule.category_2_percentは6以上7以下、rule.category_3aとrule.category_3bは整数で0以上。
        // inputsはnull許容の配列、inputs.*もnull許容の配列、
        // inputs.*.*はnull許容の整数で0以上。
        // 加算メニュー/検証/同期をこの施設の種別で出し分ける（検証ルール構築より前に設定）。

        $this->useEvidenceFacilityTypeCode(
            $this->resolveFacilityTypeCode((string) ($request->input('annual.facility_type') ?? ''))
        );
        $request->validate($this->buildUpdateValidationRules());

        $facilityId = (int)$request->input('facility_id');
        $fiscalYear = (int)$request->input('fiscal_year');
        $facility = Facility::findOrFail($facilityId);
        $months = FiscalYear::months($fiscalYear);

        $annual = $request->input('annual', []);
        $regionCode = isset($annual['region_code']) ? trim((string) $annual['region_code']) : null;
        $regionCode = $regionCode === '' ? null : $regionCode;
        $capacity = (int)($annual['capacity'] ?? 0);
        // 認定別定員：空欄は null のまま（保育所は総定員 capacity を使うフォールバックを保つ）。
        // $annual を定義した後に読むこと（上で読むと $annual 未定義で常に null になる）。
        $capacityNursery = isset($annual['capacity_nursery']) && $annual['capacity_nursery'] !== '' ? (int) $annual['capacity_nursery'] : null;
        $capacityKindergarten = isset($annual['capacity_kindergarten']) && $annual['capacity_kindergarten'] !== '' ? (int) $annual['capacity_kindergarten'] : null;
        $facilityType = trim((string)($annual['facility_type'] ?? ''));
        // 区分１～３のruleを取得。
        // category_1_percentは2以上12以下の整数、category_2_percentは6以上7以下の整数、
        // category_3aとcategory_3bは0以上の整数であることがバリデーションで保証されている。
        $rule = $request->input('rule', []);
        $category1Percent = (float)($rule['category_1_percent'] ?? 0);
        $category2Percent = (float)($rule['category_2_percent'] ?? 0);
        $category3a = (int)($rule['category_3a'] ?? 0);
        $category3b = (int)($rule['category_3b'] ?? 0);
        // inputsはコードと年月の組み合わせで園児数を表す。
        // 例えば、inputs['CAP_23_AGE0']['2024-04'] = 5 なら、
        // 2024年度4月の0歳園児数が5人という意味になる。
        // バリデーションでnull許容の整数で0以上であることが保証されている。
        /** @var array<string, array<string, int|string|null>> $inputs */
        $inputs = $request->input('inputs', []);

        // 見込は標準/短を分けない＝単層の児童数を「全部standard」に組み立てて計算機へ渡す
        // （計算機は基本分単価を inputsByDuration から計算するため）。
        $inputsByDuration = [];
        foreach ($inputs as $code => $byYm) {
            foreach ($byYm as $ym => $n) {
                $inputsByDuration[$code]['standard'][$ym] = $n;
            }
        }

        /** @var array<string, array<string, array{is_selected: bool, input_value: ?int}>> $addons */
        $addons = $this->normalizeAddonInputs($request->input('addons', []), $months);
        $codes = EvidenceInputCodeCatalog::codes();
        // monthlyTotalsForSyncは、単価計算に必要なデータが揃っていれば、
        // 月別の合計金額をコードと年月の組み合わせで表す連想配列になる。
        // 例えば、monthlyTotalsForSync['BASIC_UNIT_PRICE']['2024-04'] = 12345.67 なら、
        // 2024年度4月の基本分単価の合計金額が12345.67円という意味になる。
        // 単価計算に必要なデータが不足している場合はnullになる。
        /** @var array<string, array<string, float>>|null $monthlyTotalsForSync */
        $monthlyTotalsForSync = $this->monthlyActualsCalculator->buildMonthlyTotalsForSync(
            $fiscalYear,
            $months,
            $regionCode,
            $capacity,
            $facilityType,
            $category1Percent,
            $category2Percent,
            $inputs,
            $addons,
            null,
            $inputsByDuration,
            $category3a,
            $category3b,
            $capacity,
            false,
            $capacityNursery,
            $capacityKindergarten
        );
        // useで外部の変数を取得。トランザクションでまとめて保存する。
        // まず、facility_settingsテーブルに施設ごとの年度設定を保存する。
        DB::transaction(function () use (
            $facilityId,
            $fiscalYear,
            $facility,
            $regionCode,
            $capacity,
            $capacityNursery,
            $capacityKindergarten,
            $facilityType,
            $category1Percent,
            $category2Percent,
            $category3a,
            $category3b,
            $months,
            $codes,
            $inputs,
            $addons,
            $monthlyTotalsForSync
        ) {
            $existingSetting = FacilitySetting::query()
                ->where('facility_id', $facilityId)
                ->where('fiscal_year', $fiscalYear)
                ->first();
            // 次に、treatment_improvement_rulesテーブルに区分1～3のルールを保存する。
            FacilitySetting::updateOrCreate(
                ['facility_id' => $facilityId, 'fiscal_year' => $fiscalYear],
                $this->buildAnnualSettingPayload($facility, $existingSetting, $regionCode, $capacity, $capacityNursery, $capacityKindergarten, $facilityType)
            );
            // 次に、evidence_inputs（budgetスコープ）に月次の園児数を保存する。
            TreatmentImprovementRule::updateOrCreateForFacility($facilityId, $fiscalYear, [
                'category_1_percent' => $category1Percent,
                'category_2_percent' => $category2Percent,
                'category_3a' => $category3a,
                'category_3b' => $category3b,
            ]);

            // evidence_inputsテーブルには、
            // 施設ID・年月・コードの組み合わせで園児数を保存する。
            // 更新の前に、該当施設・年月・コードのレコードをまとめて削除。
            EvidenceInput::query()
                ->where('input_scope', 'budget')
                ->where('facility_id', $facilityId)
                ->whereIn('year_month', $months)
                ->whereIn('input_code', $codes)
                ->delete();

            $rows = [];
            $now = now();
            // rows配列に、保存するレコードのデータをまとめていく。
            // ループはコードと年月の二重ループで回す。
            // コードと年月の組み合わせで、inputsから園児数を取ってくる。
            foreach ($codes as $code) {
                foreach ($months as $ym) {
                    $raw = $inputs[$code][$ym] ?? null;

                    // 空欄は0
                    $val = ($raw === null || $raw === '') ? 0 : (int)$raw;
                    if ($val < 0) $val = 0; // 念のため

                    $rows[] = [
                        'facility_id' => $facilityId,
                        'input_scope' => 'budget',
                        'year_month' => $ym,
                        'input_code' => $code,
                        'value' => $val,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            // もしinputsに値がなければ0にする。
            // 負の数が入っている可能性もあるので、その場合も0にする。
            // rows配列には、施設ID・年月・コード・園児数・作成日時・更新日時を格納する。
            if (!empty($rows)) {
                EvidenceInput::insert($rows);
            }

            $this->ensureSyncSubsidyMasterRows($monthlyTotalsForSync !== null);
            $this->syncAddonInputs($facilityId, $fiscalYear, $months, $addons);

            // 最後に、月次の合計金額をsubsidy_budgetsテーブルに保存する。
            if ($monthlyTotalsForSync !== null) {
                foreach ($this->calculatedSubsidyCodes() as $subsidyCode) {
                    $this->syncBudget(
                        $facilityId,
                        $fiscalYear,
                        $months,
                        $subsidyCode,
                        $monthlyTotalsForSync[$subsidyCode] ?? []
                    );
                }
            }
        });

        return redirect()
            ->route('evidence.budgets.inputs.index', [
                'facility_id' => $facilityId,
                'fiscal_year' => $fiscalYear,
            ])
            ->with('success', '入力を保存しました。');
    }

    /**
     * update 用バリデーションの本体ルールを組み立てる。
     *
     * ポイント:
     * - 基本入力（facility/year/annual/rule/inputs）を定義
     * - addon の個別ルールは `buildAddonValidationRules()` で動的に追加
     */
    private function buildUpdateValidationRules(): array
    {
        return array_merge([
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'fiscal_year' => ['required', 'integer'],
            'annual' => ['required', 'array'],
            'annual.region_code' => ['nullable', 'string', 'max:50'],
            'annual.capacity' => ['required', 'integer', 'min:0'],
            'annual.capacity_nursery' => ['nullable', 'integer', 'min:0'],
            'annual.capacity_kindergarten' => ['nullable', 'integer', 'min:0'],
            'annual.facility_type' => ['required', 'string', 'max:255'],
            'rule' => ['required', 'array'],
            'rule.category_1_percent' => ['required', 'numeric'],
            'rule.category_2_percent' => ['required', 'numeric'],
            'rule.category_3a' => ['required', 'integer', 'min:0'],
            'rule.category_3b' => ['required', 'integer', 'min:0'],
            'inputs' => ['nullable', 'array'], // inputs[code][year_month] = value
            'inputs.*' => ['nullable', 'array'],
            'inputs.*.*' => ['nullable', 'integer', 'min:0'],
            'addons' => ['nullable', 'array'],
        ], $this->buildAddonValidationRules());
    }

    /**
     * addon 定義に応じてバリデーションルールを動的生成する。
     *
     * 例:
     * - checkbox 型: `'in:1'`
     * - number 型: `integer|min:0`
     */
    private function buildAddonValidationRules(): array
    {
        $rules = [];
        $addonDefinitions = $this->evidenceAddonDefinitions();

        foreach ($addonDefinitions as $uiCode => $definition) {
            $rules["addons.$uiCode"] = ['nullable', 'array'];

            $type = $definition['type'] ?? null;
            if ($type === 'checkbox') {
                $rules["addons.$uiCode.*"] = ['nullable', 'in:1'];
            } elseif ($type === 'select') {
                $rules["addons.$uiCode.*"] = ['nullable', 'string', 'max:100'];
            } else {
                $rules["addons.$uiCode.*"] = ['nullable', 'integer', 'min:0'];
            }
        }

        return $rules;
    }

    /**
     * addon 入力の現在値を、画面描画しやすい2次元配列に整形する。
     *
     * 出力形式:
     * - `$values[uiCode][year_month] = ['is_selected' => bool, 'input_value' => string|null]`
     *
     * 処理概要:
     * 1. 全 uiCode × 全月を未選択で初期化
     * 2. subsidy_inputs（budget scope）から保存済み値を取得
     * 3. subsidy_code -> uiCode へ逆引きして上書き
     *
     * @param array<int, string> $months
     * @return array<string, array<string, array{is_selected: bool, input_value: ?string}>>
     */
    private function buildAddonValues(int $facilityId, int $fiscalYear, array $months): array
    {
        $values = [];
        $addonDefinitions = $this->evidenceAddonDefinitions();
        foreach (array_keys($addonDefinitions) as $uiCode) {
            foreach ($months as $ym) {
                $values[$uiCode][$ym] = [
                    'is_selected' => false,
                    'input_value' => null,
                ];
            }
        }

        $subsidyToUi = [];
        foreach ($addonDefinitions as $uiCode => $definition) {
            $subsidyToUi[$definition['subsidy_code']] = $uiCode;
        }

        $records = SubsidyInput::query()
            ->where('input_scope', 'budget')
            ->where('facility_id', $facilityId)
            ->where('fiscal_year', $fiscalYear)
            ->whereIn('year_month', $months)
            ->whereIn('subsidy_code', array_keys($subsidyToUi))
            ->get(['year_month', 'subsidy_code', 'is_selected', 'input_value']);

        foreach ($records as $record) {
            $uiCode = $subsidyToUi[$record->subsidy_code] ?? null;
            if ($uiCode === null) {
                continue;
            }

            $definition = $addonDefinitions[$uiCode] ?? null;
            $displayValue = $record->input_value;
            if (($definition['type'] ?? null) === 'number' && $displayValue !== null) {
                $displayValue = (string) ((int) $displayValue);
            }

            $values[$uiCode][$record->year_month] = [
                'is_selected' => (bool) $record->is_selected,
                'input_value' => $displayValue === null ? null : (string) $displayValue,
            ];
        }

        return $values;
    }

    /**
     * フォームの生 addon 入力を、保存/計算で使う内部表現へ正規化する。
     *
     * 仕様:
     * - checkbox: `'1'` のみ true
     * - number: 空文字は null、負値は 0 へ丸める
     * - number 型の `is_selected` は「値が 1 以上か」で判定する
     *
     * @param array<string, array<string, mixed>> $rawAddons
     * @param array<int, string> $months
     * @return array<string, array<string, array{is_selected: bool, input_value: ?int}>>
     */
    private function normalizeAddonInputs(array $rawAddons, array $months): array
    {
        $addons = [];
        $addonDefinitions = $this->evidenceAddonDefinitions();

        foreach ($addonDefinitions as $uiCode => $definition) {
            foreach ($months as $ym) {
                if ($definition['type'] === 'checkbox') {
                    $isSelected = (string) ($rawAddons[$uiCode][$ym] ?? '') === '1';
                    $addons[$uiCode][$ym] = [
                        'is_selected' => $isSelected,
                        'input_value' => null,
                    ];
                    continue;
                }

                $raw = $rawAddons[$uiCode][$ym] ?? null;
                $value = ($raw === null || $raw === '') ? null : (int) $raw;
                if ($value !== null && $value < 0) {
                    $value = 0;
                }
                $addons[$uiCode][$ym] = [
                    'is_selected' => $value !== null && $value > 0,
                    'input_value' => $value,
                ];
            }
        }

        return $addons;
    }

    /**
     * addon 入力を subsidy_inputs（budget scope）へ upsert する。
     *
     * キー:
     * - facility_id + input_scope + fiscal_year + year_month + subsidy_code
     *
     * 更新列:
     * - is_selected / input_value / updated_at
     *
     * @param array<int, string> $months
     * @param array<string, array<string, array{is_selected: bool, input_value: ?int}>> $addons
     */
    private function syncAddonInputs(int $facilityId, int $fiscalYear, array $months, array $addons): void
    {
        $rows = [];
        $now = now();
        $addonDefinitions = $this->evidenceAddonDefinitions();
        foreach ($addonDefinitions as $uiCode => $definition) {
            foreach ($months as $ym) {
                $state = $addons[$uiCode][$ym] ?? ['is_selected' => false, 'input_value' => null];
                $rows[] = [
                    'facility_id' => $facilityId,
                    'input_scope' => 'budget',
                    'fiscal_year' => $fiscalYear,
                    'year_month' => $ym,
                    'subsidy_code' => $definition['subsidy_code'],
                    'is_selected' => (bool) ($state['is_selected'] ?? false),
                    'input_value' => $state['input_value'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($rows)) {
            SubsidyInput::query()->upsert(
                $rows,
                ['facility_id', 'input_scope', 'fiscal_year', 'year_month', 'subsidy_code'],
                ['is_selected', 'input_value', 'updated_at']
            );
        }
    }

    /**
     * 同期対象の subsidy_code が subsidy_master に存在することを保証する。
     *
     * 計算機が金額を出す全コード（calculatedSubsidyCodes＝共有トレイト）を基準にマスタ行を用意する。
     * 旧実装は静的 INPUT_LINKED_CODES＋専用ラッパー2メソッドだったが、実績(ActualsInputsController)と
     * 同じインライン形へそろえ、薄いラッパー(addonSyncCodes/monthlySyncSubsidyCodes)を廃止した。
     *
     * `includeMonthlyCodes=true` のとき:
     * - 基本分(BASIC_UNIT_PRICE)を除いた全コードに加えて
     * - 月次計算が成立した場合のみ基本分を含む全コードも対象にする
     */
    private function ensureSyncSubsidyMasterRows(bool $includeMonthlyCodes): void
    {
        // 計算機が出す全コードのうち基本分を除いたぶんをマスタ準備対象にする
        // （基本分は月次計算が成立したときだけ下で追加する）。
        $codes = array_values(array_filter(
            $this->calculatedSubsidyCodes(),
            static fn (string $code): bool => $code !== SubsidyCodes::BASIC_UNIT_PRICE
        ));
        if ($includeMonthlyCodes) {
            $codes = array_merge($codes, $this->calculatedSubsidyCodes());
        }

        // 取得・補完・upsert を一括実行して N+1 クエリを避ける。
        app(SubsidyMasterSyncService::class)->sync(array_values(array_unique($codes)));
    }

    /**
     * 年度設定は facility_settings を最優先し、未設定は facilities の固定値を初期値に使う。
     *
     * @return array{region_code: ?string, capacity: ?int, capacity_nursery: ?int, capacity_kindergarten: ?int, facility_type: ?string}
     *
     */
    private function resolveAnnualSetting(Facility $facility, int $fiscalYear): array
    {
        $setting = FacilitySetting::query()
            ->where('facility_id', $facility->id)
            ->where('fiscal_year', $fiscalYear)
            ->first();

        return [
            'region_code' => $setting?->region_code ?? $facility->region_code,
            'capacity' => $setting?->capacity ?? $facility->capacity,
            'capacity_nursery' => $setting?->capacity_nursery ?? $facility->capacity_nursery,
            'capacity_kindergarten' => $setting?->capacity_kindergarten ?? $facility->capacity_kindergarten,
            'facility_type' => $setting?->facility_type ?? $facility->facility_type,
        ];
    }

    /**
     * facility_settings 保存用の payload を組み立てる。
     *
     * ポイント:
     * - open/close は「既存設定 > 施設値 > デフォルト」の順で採用
     * - open >= close の不正時刻は安全な既定値へ矯正
     * - boundary_* は既存値を優先し、未設定時は open/close を使う
     */
    private function buildAnnualSettingPayload(
        Facility $facility,
        ?FacilitySetting $existingSetting,
        ?string $regionCode,
        int $capacity,
        ?int $capacityNursery,
        ?int $capacityKindergarten,
        string $facilityType
    ): array {
        $openTime = $existingSetting?->open_time ?? $facility->open_time ?? '07:30:00';
        $closeTime = $existingSetting?->close_time ?? $facility->close_time ?? '19:00:00';

        if ($openTime >= $closeTime) {
            $openTime = '07:30:00';
            $closeTime = '19:00:00';
        }

        return [
            'region_code' => $regionCode,
            'capacity' => $capacity,
            'capacity_nursery' => $capacityNursery,
            'capacity_kindergarten' => $capacityKindergarten,
            'facility_type' => $facilityType,
            'open_time' => $openTime,
            'close_time' => $closeTime,
            'boundary_morning' => $existingSetting?->boundary_morning ?? $openTime,
            'boundary_core' => $existingSetting?->boundary_core ?? $openTime,
            'boundary_evening' => $existingSetting?->boundary_evening ?? $closeTime,
        ];
    }

    /**
     * 1つの subsidy_code について、12か月分の planned_amount を subsidy_budgets へ upsert する。
     *
     * 入力:
     * - `$monthlyTotals['YYYY-MM'] = 金額`
     *
     * 補足:
     * - 保存値は `round` 後の int に統一する（DB定義が unsignedInteger のため）
     */
    private function syncBudget(
        int $facilityId,
        int $fiscalYear,
        array $months,
        string $subsidyCode,
        array $monthlyTotals
    ): void
    {
        $now = now();
        $rows = [];
        foreach ($months as $ym) {
            $rows[] = [
                'facility_id' => $facilityId,
                'fiscal_year' => $fiscalYear,
                'year_month' => $ym,
                'subsidy_code' => $subsidyCode,
                'planned_amount' => (int) round((float) ($monthlyTotals[$ym] ?? 0)),
                'planned_children_count' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SubsidyBudget::query()->upsert(
            $rows,
            ['facility_id', 'year_month', 'subsidy_code'],
            ['fiscal_year', 'planned_amount', 'planned_children_count', 'updated_at']
        );
    }

    /**
     * 施設種別文字列を official_price 検索用コードへ変換する。
     *
     * 変換定義の実体は `FacilityTypeCodeCatalog` が持つ。
     */
    private function resolveFacilityTypeCode(?string $facilityType): ?string
    {
        return FacilityTypeCodeCatalog::resolve($facilityType);
    }

}
