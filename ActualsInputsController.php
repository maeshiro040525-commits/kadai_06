<?php

namespace App\Http\Controllers\Evidence;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Evidence\Concerns\ResolvesEvidenceAddonRules;
use App\Http\Requests\Evidence\UpdateActualsInputRequest;
use App\Models\CareDurationType;
use App\Models\Emploee;
use App\Models\EmploeeAssignment;
use App\Models\EvidenceInputCode;
use App\Models\EvidenceInput;
use App\Models\Facility;
use App\Models\FacilitySetting;
use App\Models\OfficialPrice;
use App\Models\SubsidyActual;
use App\Models\SubsidyInput;
use App\Models\TreatmentImprovementRule;
use App\Services\Evidence\MonthlyActualsCalculator;
use App\Services\Evidence\SubsidyMasterSyncService;
use App\Support\FiscalYear;
use App\Support\EvidenceAddonStaffAssignmentCatalog;
use App\Support\EvidenceInputCodeCatalog;
use App\Support\OfficialPriceItemCodeCatalog;
use App\Support\SubsidyCodes;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;


/**
 * 実績入力画面の表示・保存・月次補助金計算を統括する Controller。
 *
 * データ形状メモ（ここだけ見れば追える版）:
 * - `$values[input_code][year_month] = '人数(文字列)'`
 * - `$addonValues[ui_code][year_month] = ['is_selected' => bool, 'input_value' => ?string]`
 * - `$inputs[input_code][year_month] = int|string|null`（リクエスト生値）
 * - `$addons[ui_code][year_month] = ['is_selected' => bool, 'input_value' => ?int]`（正規化後）
 * - `$monthlyTotalsForSync[subsidy_code][year_month] = float`
 */
class ActualsInputsController extends Controller
{
    use ResolvesEvidenceAddonRules;

    public function __construct(
        private readonly MonthlyActualsCalculator $monthlyActualsCalculator
    ) {
    }

    private const CAP23_MIN_STAFFING_UI_CODE = EvidenceAddonStaffAssignmentCatalog::CAP23_MIN_STAFFING_UI_CODE;
    private const ADDON_STAFF_LEGACY_SUBSIDY_CODE_ALIASES = [
        self::CAP23_MIN_STAFFING_UI_CODE => [
            SubsidyCodes::BASIC_UNIT_PRICE,
        ],
    ];

    /**
     * 実績の根拠入力（年齢別人数・年度設定・区分ルール）を扱う。
     * 入力値から月次合計を計算し、関連テーブルへ同期保存する。
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
            return view('evidence.actuals.input', [
                'facility' => null,
                'facilityId' => null,
                'facilities' => $facilities,
                'fiscalYear' => $fiscalYear,
                'months' => [],
                'rows' => $rows,
                'values' => [],
                'durationTypes' => collect(),
                'addonValues' => [],
                'addonStaffValues' => [],
                'checkboxAddonDefinitions' => [],
                'selectAddonDefinitions' => [],
                'unifiedAddonDefinitions' => [],
                'staffAssignableUiCodes' => [],
                'teamCareStaffOptions' => [],
                'fixedAmountAddonRows' => [],
                'selectAmountAddonRows' => [],
                'monthlyMinimumStaffingSlots' => [],
                'monthlyStaffingDivisorsByInputCode' => [],
                'ruleInput' => null,
            ]);
        }

        $months = FiscalYear::months($fiscalYear);
        $annualSetting = $this->resolveAnnualSetting($facility, $fiscalYear);
        $rule = TreatmentImprovementRule::resolveForFacilityAndFiscalYear($facilityId, $fiscalYear);

        // 加算メニューをこの施設の種別で出し分ける（こども園は登録加算のみ／保育園は従来どおり全件）。
        // 種別未対応のときは null＝全件にフォールバック。trait の加算定義メソッド使用より前に設定する。
        $facilityTypeCodeForMenu = $this->monthlyActualsCalculator->resolveFacilityTypeCode((string) ($annualSetting['facility_type'] ?? ''));
        $this->useEvidenceFacilityTypeCode($facilityTypeCodeForMenu);

        // 入力グリッドの児童数行も施設の認定で絞る（保育所に1号行を出さない／こども園は2・3号＋1号）。
        $facilityCerts = $facilityTypeCodeForMenu === 'KODOMOEN' ? ['23', '1'] : ['23'];
        $rows = EvidenceInputCodeCatalog::rowsForCerts($facilityCerts);

        // 施設種別から保育時間区分を取得
        $facilityTypeForDuration = $annualSetting['facility_type'] ?: '保育園';
        $durationTypes = CareDurationType::forFacilityType($facilityTypeForDuration);
        if ($durationTypes->isEmpty()) {
            // フォールバック: 保育園のデフォルト区分を使用
            $durationTypes = CareDurationType::forFacilityType('保育園');
        }

        // evidence_inputs（actualスコープ）から該当施設・年月・コードのレコードをまとめて取ってくる
        $records = EvidenceInput::query()
            ->where('input_scope', 'actual')
            ->where('facility_id', $facilityId)
            ->whereIn('year_month', $months)
            ->whereIn('input_code', array_keys($rows))
            ->get();

        /** @var array<string, array<string, array<string, string>>> $values */
        // values[input_code][care_duration_type][year_month] = value の形で配列に格納する
        $values = [];
        foreach ($records as $r) {
            $duration = $r->care_duration_type ?? 'standard';
            $values[$r->input_code][$duration][$r->year_month] = (string)((int)$r->value);
        }

        // calculator や人割当枠の計算に渡すため、duration を合算した2次元配列を作る
        // $summedValues[input_code][year_month] = 合計人数
        $summedValues = $this->sumValuesByDuration($values);

        $currentStaffingDivisorsByInputCode = $this->loadCurrentStaffingDivisorsByInputCode();
        $monthlyStaffingDivisorsByInputCode = $this->resolveMonthlyStaffingDivisorsByInputCode(
            $months,
            $currentStaffingDivisorsByInputCode
        );
        $monthlyMinimumStaffingSlots = $this->buildMonthlyMinimumStaffingSlots(
            $summedValues,
            $months,
            $monthlyStaffingDivisorsByInputCode
        );

        /** @var array<string, array<string, array{is_selected: bool, input_value: ?string}>> $addonValues */
        $addonValues = $this->buildAddonValues($facilityId, $fiscalYear, $months);
        /** @var array<string, array<string, array<int, int>>> $addonStaffValues */
        $addonStaffValues = $this->buildAddonStaffValues($facilityId, $months);
        $checkboxAddonDefinitions = $this->checkboxAddonDefinitions();
        $selectAddonDefinitions = $this->evidenceSelectAddonDefinitions();
        $unifiedAddonDefinitions = $this->buildUnifiedAddonDefinitions($checkboxAddonDefinitions, $selectAddonDefinitions);
        $staffAssignableUiCodes = array_keys(EvidenceAddonStaffAssignmentCatalog::selectableDefinitions());
        $teamCareStaffOptions = $this->buildTeamCareStaffOptions($facilityId, $fiscalYear, $addonStaffValues);

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

        $regionCode = $annualSetting['region_code'];
        $capacity = $annualSetting['capacity'];
        $capacityNursery = $annualSetting['capacity_nursery'] ?? null;
        $capacityKindergarten = $annualSetting['capacity_kindergarten'] ?? null;
        $facilityType = $annualSetting['facility_type'];
        $category1Percent = (float)($rule?->category_1_percent ?? 0);
        $category2Percent = (float)($rule?->category_2_percent ?? 0);

        // $facilityType の表示名を、計算で使う内部コードに変換している。
        // 変換ルールは facility_type_mappings で管理し、未対応の場合は null になる。
        $facilityTypeCode = $this->monthlyActualsCalculator->resolveFacilityTypeCode($facilityType);

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
        $class12ItemCodesByComponent = OfficialPriceItemCodeCatalog::class12ItemCodesByComponent();
        $class12ItemCodes = array_values($class12ItemCodesByComponent);
        /** @var EloquentCollection<int, OfficialPrice>|null $officialPrices */
        $officialPrices = null;


        // データがない場合は、単価の取得に進む。単価は official_prices テーブルから取ってくる。
        if (empty($errorsCalc)) {
            $officialPrices = $this->monthlyActualsCalculator->queryOfficialPrices(
                $fiscalYear,
                (string) $regionCode,
                $facilityTypeCode,
                (int) $capacity,
                $capacityNursery !== null ? (int) $capacityNursery : null,
                $capacityKindergarten !== null ? (int) $capacityKindergarten : null
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

            $errorsCalc = array_merge(
                $errorsCalc,
                $this->monthlyActualsCalculator->buildMissingAgeErrors($unitPrices, 'official_prices')
            );
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
            $errorsCalc = array_merge(
                $errorsCalc,
                array_values($this->monthlyActualsCalculator->buildFixedAmountAddonValidationMessages(
                    $months,
                    $addonValues,
                    $officialPrices,
                    $facilityTypeCode
                )),
                array_values($this->monthlyActualsCalculator->buildSelectAddonValidationMessages(
                    $months,
                    $addonValues,
                    $officialPrices,
                    $facilityTypeCode
                ))
            );
        }
        $isBranch = $annualSetting['is_branch'];
        $monthlyTotalsForView = $this->monthlyActualsCalculator->buildMonthlyTotalsForSync(
            $fiscalYear,
            $months,
            $regionCode,
            (int) ($capacity ?? 0),
            (string) ($facilityType ?? ''),
            $category1Percent,
            $category2Percent,
            $summedValues,
            $addonValues,
            $officialPrices,
            $values, // duration別の3次元配列 [code][duration][ym]
            (int) ($rule?->category_3a ?? 0),
            (int) ($rule?->category_3b ?? 0),
            (int) ($capacity ?? 0),
            $isBranch,
            $capacityNursery !== null ? (int) $capacityNursery : null,
            $capacityKindergarten !== null ? (int) $capacityKindergarten : null
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
        // 副食費徴収免除加算（pass-through）。計算はされているので表示用に展開する（区分3と同じ扱い）。
        $monthlyFoodFee = $monthlyTotalsForView[SubsidyCodes::FOOD_FEE_EXEMPTION] ?? array_fill_keys($months, 0.0);
        $fixedAmountAddonRows = $this->buildFixedAmountAddonRows($months, $monthlyTotalsForView);
        $selectAmountAddonRows = $this->buildSelectAmountAddonRows($months, $monthlyTotalsForView);


        return view('evidence.actuals.input', [
            'facility' => $facility,
            'facilityId' => $facilityId,
            'facilities' => $facilities,
            'fiscalYear' => $fiscalYear,
            'months' => $months,
            'rows' => $rows,
            'values' => $values,
            'durationTypes' => $durationTypes,
            'addonValues' => $addonValues,
            'addonStaffValues' => $addonStaffValues,
            'checkboxAddonDefinitions' => $checkboxAddonDefinitions,
            'selectAddonDefinitions' => $selectAddonDefinitions,
            'unifiedAddonDefinitions' => $unifiedAddonDefinitions,
            'staffAssignableUiCodes' => $staffAssignableUiCodes,
            'teamCareStaffOptions' => $teamCareStaffOptions,
            'fixedAmountAddonRows' => $fixedAmountAddonRows,
            'selectAmountAddonRows' => $selectAmountAddonRows,
            'monthlyMinimumStaffingSlots' => $monthlyMinimumStaffingSlots,
            'monthlyStaffingDivisorsByInputCode' => $monthlyStaffingDivisorsByInputCode,
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
            'monthlyFoodFee' => $monthlyFoodFee,
            'regionCode' => $regionCode,
        ]);
    }

    public function update(UpdateActualsInputRequest $request)
    {
        $facilityId = (int)$request->input('facility_id');
        $fiscalYear = (int)$request->input('fiscal_year');
        $facility = Facility::findOrFail($facilityId);
        $months = FiscalYear::months($fiscalYear);

        $annual = $request->input('annual', []);
        $regionCode = isset($annual['region_code']) ? trim((string) $annual['region_code']) : null;
        $regionCode = $regionCode === '' ? null : $regionCode;
        $capacity = (int)($annual['capacity'] ?? 0);
        $facilityType = trim((string)($annual['facility_type'] ?? ''));
        $isBranch = (bool) ($annual['is_branch'] ?? false);
        // 加算メニュー/同期をこの施設の種別で出し分ける（trait の加算定義メソッド使用より前に設定）。
        $this->useEvidenceFacilityTypeCode(
            $this->monthlyActualsCalculator->resolveFacilityTypeCode($facilityType)
        );
        // こども園の認定別利用定員：フォーム入力が無ければ保存済み設定（facility_settings）を使う
        $savedAnnual = $this->resolveAnnualSetting($facility, $fiscalYear);
        $capacityNursery = $annual['capacity_nursery'] ?? $savedAnnual['capacity_nursery'] ?? null;
        $capacityKindergarten = $annual['capacity_kindergarten'] ?? $savedAnnual['capacity_kindergarten'] ?? null;
        $capacityNursery = $capacityNursery !== null ? (int) $capacityNursery : null;
        $capacityKindergarten = $capacityKindergarten !== null ? (int) $capacityKindergarten : null;
        // 区分１～３のruleを取得。
        // category_1_percentは2以上12以下の整数、category_2_percentは6以上7以下の整数、
        // category_3aとcategory_3bは0以上の整数であることがバリデーションで保証されている。
        $rule = $request->input('rule', []);
        $category1Percent = (float)($rule['category_1_percent'] ?? 0);
        $category2Percent = (float)($rule['category_2_percent'] ?? 0);
        $category3a = (int)($rule['category_3a'] ?? 0);
        $category3b = (int)($rule['category_3b'] ?? 0);
        // inputsはコードと保育時間区分と年月の組み合わせで園児数を表す。
        // 例えば、inputs['CAP_23_AGE0']['standard']['2024-04'] = 5 なら、
        // 2024年度4月の0歳・標準時間の園児数が5人という意味になる。
        // バリデーションでnull許容の整数で0以上であることが保証されている。
        /** @var array<string, array<string, array<string, int|string|null>>> $inputs */
        $inputs = $request->input('inputs', []);
        // calculator に渡すため、duration を合算した2次元配列を作る
        $summedInputs = $this->sumValuesByDuration($inputs);
        /** @var array<string, array<string, array<int, int>>> $addonStaffSelections */
        $addonStaffSelections = $this->normalizeAddonStaffInputs(
            $request->input('addon_staff', []),
            $months
        );
        /** @var array<string, array<string, array{is_selected: bool, input_value: ?int}>> $addons */
        $addons = $this->normalizeAddonInputs(
            $request->input('addons', []),
            $months,
            $addonStaffSelections
        );
        $currentStaffingDivisorsByInputCode = $this->loadCurrentStaffingDivisorsByInputCode();
        $monthlyStaffingDivisorsByInputCode = $this->resolveMonthlyStaffingDivisorsByInputCode(
            $months,
            $currentStaffingDivisorsByInputCode
        );
        $monthlyMinimumStaffingSlots = $this->buildMonthlyMinimumStaffingSlots(
            $summedInputs,
            $months,
            $monthlyStaffingDivisorsByInputCode
        );
        // TODO: 本番運用時に有効化する。開発中は職員割当の最低人数チェックをスキップ
        // $this->validateCap23StaffAssignmentsAgainstMinimum($addonStaffSelections, $months, $monthlyMinimumStaffingSlots);
        $codes = EvidenceInputCodeCatalog::codes();

        // 施設種別から保育時間区分を取得（update時）
        $facilityTypeForDuration = $facilityType ?: '保育園';
        $durationTypes = CareDurationType::forFacilityType($facilityTypeForDuration);
        if ($durationTypes->isEmpty()) {
            $durationTypes = CareDurationType::forFacilityType('保育園');
        }
        $officialPrices = null;
        $facilityTypeCode = $this->monthlyActualsCalculator->resolveFacilityTypeCode($facilityType);
        if ($regionCode && $facilityTypeCode) {
            $officialPrices = $this->monthlyActualsCalculator->queryOfficialPrices(
                $fiscalYear,
                $regionCode,
                $facilityTypeCode,
                $capacity,
                $capacityNursery,
                $capacityKindergarten
            );
            $fixedAmountAddonMessages = $this->monthlyActualsCalculator->buildFixedAmountAddonValidationMessages(
                $months,
                $addons,
                $officialPrices,
                $facilityTypeCode
            );
            $selectAddonMessages = $this->monthlyActualsCalculator->buildSelectAddonValidationMessages(
                $months,
                $addons,
                $officialPrices,
                $facilityTypeCode
            );
            $allAddonMessages = array_merge($fixedAmountAddonMessages, $selectAddonMessages);
            if ($allAddonMessages !== []) {
                throw ValidationException::withMessages($allAddonMessages);
            }
        }
        // monthlyTotalsForSyncは、単価計算に必要なデータが揃っていれば、
        // 月別の合計金額をコードと年月の組み合わせで表す連想配列になる。
        // 例えば、monthlyTotalsForSync['BASIC_UNIT_PRICE']['2024-04'] = 12345.67 なら、
        // 2024年度4月の基本分単価の合計金額が12345.67円という意味になる。
        // 単価計算に必要なデータが不足している場合はnullになる。
        /** @var array<string, array<string, float>>|null $monthlyTotalsForSync */
        $isBranch = (bool) ($this->resolveAnnualSetting($facility, $fiscalYear)['is_branch'] ?? false);
        $monthlyTotalsForSync = $this->monthlyActualsCalculator->buildMonthlyTotalsForSync(
            $fiscalYear,
            $months,
            $regionCode,
            $capacity,
            $facilityType,
            $category1Percent,
            $category2Percent,
            $summedInputs,
            $addons,
            $officialPrices,
            $inputs, // duration別の3次元配列 [code][duration][ym]
            $category3a,
            $category3b,
            $capacity,
            $isBranch,
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
            $facilityType,
            $category1Percent,
            $category2Percent,
            $category3a,
            $category3b,
            $months,
            $codes,
            $inputs,
            $durationTypes,
            $addons,
            $addonStaffSelections,
            $monthlyStaffingDivisorsByInputCode,
            $monthlyTotalsForSync,
            $isBranch
        ) {
            $existingSetting = FacilitySetting::query()
                ->where('facility_id', $facilityId)
                ->where('fiscal_year', $fiscalYear)
                ->first();
            // 次に、treatment_improvement_rulesテーブルに区分1～3のルールを保存する。
            FacilitySetting::updateOrCreate(
                ['facility_id' => $facilityId, 'fiscal_year' => $fiscalYear],
                $this->buildAnnualSettingPayload($facility, $existingSetting, $regionCode, $capacity, $facilityType, $isBranch)
            );
            // 次に、evidence_inputs（actualスコープ）に月次の園児数を保存する。
            TreatmentImprovementRule::updateOrCreateForFacility($facilityId, $fiscalYear, [
                'category_1_percent' => $category1Percent,
                'category_2_percent' => $category2Percent,
                'category_3a' => $category3a,
                'category_3b' => $category3b,
            ]);

            // evidence_inputsテーブルには、
            // 施設ID・年月・コード・保育時間区分の組み合わせで園児数を保存する。
            // 更新の前に、該当施設・年月・コードのレコードをまとめて削除。
            EvidenceInput::query()
                ->where('input_scope', 'actual')
                ->where('facility_id', $facilityId)
                ->whereIn('year_month', $months)
                ->whereIn('input_code', $codes)
                ->delete();

            $rows = [];
            $now = now();
            // rows配列に、保存するレコードのデータをまとめていく。
            // ループはコード・保育時間区分・年月の三重ループで回す。
            foreach ($codes as $code) {
                foreach ($durationTypes as $dt) {
                    foreach ($months as $ym) {
                        $raw = $inputs[$code][$dt->code][$ym] ?? null;

                        // 空欄は0
                        $val = ($raw === null || $raw === '') ? 0 : (int)$raw;
                        if ($val < 0) $val = 0; // 念のため

                        $rows[] = [
                            'facility_id' => $facilityId,
                            'input_scope' => 'actual',
                            'year_month' => $ym,
                            'input_code' => $code,
                            'care_duration_type' => $dt->code,
                            'value' => $val,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }
            // rows配列には、施設ID・年月・コード・保育時間区分・園児数・作成日時・更新日時を格納する。
            if (!empty($rows)) {
                EvidenceInput::insert($rows);
            }

            $this->ensureSyncSubsidyMasterRows($monthlyTotalsForSync !== null);
            $this->syncAddonInputs($facilityId, $fiscalYear, $months, $addons);
            $this->syncAddonStaffAssignments($facilityId, $months, $addonStaffSelections);

            if ($monthlyTotalsForSync !== null) {
                foreach ($this->calculatedSubsidyCodes() as $subsidyCode) {
                    $this->syncActual(
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
            ->route('evidence.actuals.input.index', [
                'facility_id' => $facilityId,
                'fiscal_year' => $fiscalYear,
            ])
            ->with('success', '入力を保存しました。');
    }

    /**
     * addon 入力の現在値を、画面描画しやすい2次元配列に整形する。
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
            ->where('input_scope', 'actual')
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
            // select 型: input_value には選択された subsidy_master の code がそのまま格納されている

            $values[$uiCode][$record->year_month] = [
                'is_selected' => (bool) $record->is_selected,
                'input_value' => $displayValue === null ? null : (string) $displayValue,
            ];
        }

        return $values;
    }

    /**
     * @param array<int, string> $months
     * @return array<string, array<string, array<int, int>>>
     */
    private function buildAddonStaffValues(int $facilityId, array $months): array
    {
        $values = $this->initializeAddonStaffValues($months);
        if (EvidenceAddonStaffAssignmentCatalog::selectableDefinitions() === []) {
            return $values;
        }

        $this->assertFacilityAllowanceStaffAssignmentsTableExists();
        $subsidyToUi = $this->addonStaffReadableSubsidyCodeMap();

        $records = DB::table('facility_allowance_staff_assignments')
            ->where('facility_id', $facilityId)
            ->whereIn('year_month', $months)
            ->whereIn('subsidy_code', array_keys($subsidyToUi))
            ->orderBy('id')
            ->get(['year_month', 'subsidy_code', 'staff_id']);

        foreach ($records as $record) {
            $uiCode = $subsidyToUi[$record->subsidy_code] ?? null;
            if ($uiCode === null) {
                continue;
            }

            $staffIds = $values[$uiCode][$record->year_month] ?? [];
            $staffId = (int) $record->staff_id;
            if (!in_array($staffId, $staffIds, true)) {
                $staffIds[] = $staffId;
            }
            $values[$uiCode][$record->year_month] = $staffIds;
        }

        return $values;
    }

    /**
     * フォームの生 addon 入力を、保存/計算で使う内部表現へ正規化する。
     *
     * @param array<string, array<string, mixed>> $rawAddons
     * @param array<int, string> $months
     * @param array<string, array<string, array<int, int>>> $addonStaffSelections
     * @return array<string, array<string, array{is_selected: bool, input_value: ?int}>>
     */
    private function normalizeAddonInputs(array $rawAddons, array $months, array $addonStaffSelections = []): array
    {
        $addons = [];
        $addonDefinitions = $this->evidenceAddonDefinitions();
        $selectAddonDefinitions = $this->evidenceSelectAddonDefinitions();

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

                if ($definition['type'] === 'select') {
                    // select 型: 選択された subsidy_master の code 文字列をそのまま保存する
                    $raw = trim((string) ($rawAddons[$uiCode][$ym] ?? ''));
                    $isValid = false;
                    if ($raw !== '') {
                        $selectDef = $selectAddonDefinitions[$uiCode] ?? null;
                        if ($selectDef !== null && isset($selectDef['options'][$raw])) {
                            $isValid = true;
                        }
                    }
                    $addons[$uiCode][$ym] = [
                        'is_selected' => $isValid,
                        'input_value' => $isValid ? $raw : null,
                    ];
                    continue;
                }

                $raw = $rawAddons[$uiCode][$ym] ?? null;
                $value = ($raw === null || $raw === '') ? null : (int) $raw;
                if ($value !== null && $value < 0) {
                    $value = 0;
                }
                $staffSelections = $addonStaffSelections[$uiCode][$ym] ?? [];
                $hasStaffSelection = is_array($staffSelections) && $staffSelections !== [];
                $addons[$uiCode][$ym] = [
                    'is_selected' => ($value !== null && $value > 0) || $hasStaffSelection,
                    'input_value' => $value,
                ];
            }
        }

        return $addons;
    }

    /**
     * addon 入力を subsidy_inputs（actual scope）へ upsert する。
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
                    'input_scope' => 'actual',
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
     * @param array<int, string> $months
     * @param array<string, array<string, array<int, int>>> $addonStaffSelections
     */
    private function syncAddonStaffAssignments(int $facilityId, array $months, array $addonStaffSelections): void
    {
        $staffAssignableAddons = EvidenceAddonStaffAssignmentCatalog::selectableDefinitions();
        if ($staffAssignableAddons === []) {
            return;
        }

        $this->assertFacilityAllowanceStaffAssignmentsTableExists();
        DB::table('facility_allowance_staff_assignments')
            ->where('facility_id', $facilityId)
            ->whereIn('year_month', $months)
            ->whereIn('subsidy_code', $this->addonStaffDeletableSubsidyCodes())
            ->delete();

        $rows = [];
        $now = now();
        foreach ($staffAssignableAddons as $uiCode => $subsidyCode) {
            foreach ($months as $ym) {
                $staffIds = $addonStaffSelections[$uiCode][$ym] ?? [];
                if (!is_array($staffIds) || $staffIds === []) {
                    continue;
                }

                foreach ($staffIds as $staffId) {
                    $rows[] = [
                        'facility_id' => $facilityId,
                        'year_month' => $ym,
                        'staff_id' => $staffId,
                        'subsidy_code' => $subsidyCode,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        if ($rows !== []) {
            DB::table('facility_allowance_staff_assignments')->insert($rows);
        }
    }

    /**
     * @param array<int, string> $months
     * @return array<string, array<string, array<int, int>>>
     */
    private function normalizeAddonStaffInputs(array $rawAddonStaffInputs, array $months): array
    {
        $values = $this->initializeAddonStaffValues($months);

        foreach (array_keys(EvidenceAddonStaffAssignmentCatalog::selectableDefinitions()) as $uiCode) {
            foreach ($months as $ym) {
                $raw = $rawAddonStaffInputs[$uiCode][$ym] ?? [];
                $rawValues = is_array($raw) ? $raw : [$raw];

                $staffIds = [];
                foreach ($rawValues as $rawValue) {
                    if ($rawValue === null || $rawValue === '') {
                        continue;
                    }

                    $staffId = (int) $rawValue;
                    if ($staffId < 1) {
                        continue;
                    }

                    $staffIds[$staffId] = $staffId;
                }

                $values[$uiCode][$ym] = array_values($staffIds);
            }
        }

        return $values;
    }

    /**
     * @param array<int, string> $months
     * @return array<string, array<string, array<int, int>>>
     */
    private function initializeAddonStaffValues(array $months): array
    {
        $values = [];
        foreach (array_keys(EvidenceAddonStaffAssignmentCatalog::selectableDefinitions()) as $uiCode) {
            foreach ($months as $ym) {
                $values[$uiCode][$ym] = [];
            }
        }

        return $values;
    }

    /**
     * @param array<string, array<string, array<int, int>>> $addonStaffValues
     * @return array<int, string>
     */
    private function buildTeamCareStaffOptions(int $facilityId, int $fiscalYear, array $addonStaffValues): array
    {
        if (!Schema::hasTable('emploees')) {
            return [];
        }

        $staffIds = [];
        if (Schema::hasTable('emploee_assignments')) {
            $periodStart = sprintf('%d-04-01', $fiscalYear);
            $periodEnd = sprintf('%d-03-31', $fiscalYear + 1);

            $staffIds = EmploeeAssignment::query()
                ->where('facility_id', $facilityId)
                ->whereDate('start_date', '<=', $periodEnd)
                ->where(function ($query) use ($periodStart) {
                    $query
                        ->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $periodStart);
                })
                ->orderBy('staff_id')
                ->pluck('staff_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        foreach ($addonStaffValues as $staffValuesByMonth) {
            if (!is_array($staffValuesByMonth)) {
                continue;
            }

            foreach ($staffValuesByMonth as $staffIdsByMonth) {
                if (!is_array($staffIdsByMonth)) {
                    continue;
                }

                foreach ($staffIdsByMonth as $staffId) {
                    $staffIds[] = (int) $staffId;
                }
            }
        }

        $staffIds = array_values(array_unique($staffIds));
        if ($staffIds === []) {
            return [];
        }

        $staffById = Emploee::query()
            ->whereIn('id', $staffIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->keyBy('id');

        $options = [];
        foreach ($staffById as $staff) {
            $options[(int) $staff->id] = $staff->name;
        }

        foreach ($staffIds as $staffId) {
            if (!array_key_exists($staffId, $options)) {
                $options[$staffId] = '不明な職員';
            }
        }

        asort($options, SORT_NATURAL);

        return $options;
    }

    /**
     * @return array<string, float>
     */
    private function loadCurrentStaffingDivisorsByInputCode(): array
    {
        $this->assertRequiredEvidenceInputCodeSchema();

        $query = EvidenceInputCode::currentCatalogQuery();
        $query->whereIn('certification_code', OfficialPriceItemCodeCatalog::certificationCodes());

        return $query
            ->orderBy('evidence_input_codes.display_order')
            ->orderBy('evidence_input_codes.input_code')
            ->orderBy('evidence_input_codes.id')
            ->pluck('evidence_input_codes.staffing_divisor', 'evidence_input_codes.input_code')
            ->map(static fn (mixed $value): float => (float) $value)
            ->filter(static fn (float $value): bool => $value > 0)
            ->all();
    }

    /**
     * @param array<int, string> $months
     * @param array<string, float> $currentDivisorsByInputCode
     * @return array<string, array<string, float>>
     */
    private function resolveMonthlyStaffingDivisorsByInputCode(
        array $months,
        array $currentDivisorsByInputCode
    ): array {
        $this->assertRequiredEvidenceInputCodeSchema();
        $usesEffectiveYearMonth = Schema::hasColumn('evidence_input_codes', 'effective_year_month');

        $resolved = [];
        foreach ($months as $ym) {
            $resolved[$ym] = $usesEffectiveYearMonth ? [] : $currentDivisorsByInputCode;
        }

        if ($usesEffectiveYearMonth && $months !== []) {
            $historyRows = DB::table('evidence_input_codes')
                ->whereIn('certification_code', OfficialPriceItemCodeCatalog::certificationCodes())
                ->where('effective_year_month', '<=', max($months))
                ->whereNotNull('staffing_divisor')
                ->where('staffing_divisor', '>', 0)
                ->orderBy('input_code')
                ->orderBy('effective_year_month')
                ->orderBy('id')
                ->get(['input_code', 'effective_year_month', 'staffing_divisor']);

            if ($historyRows->isNotEmpty()) {
                $historiesByInputCode = [];
                foreach ($historyRows as $row) {
                    $inputCode = (string) $row->input_code;
                    $effectiveYearMonth = (string) $row->effective_year_month;
                    $divisor = (float) $row->staffing_divisor;

                    if ($inputCode === '' || $effectiveYearMonth === '' || $divisor <= 0) {
                        continue;
                    }

                    $historiesByInputCode[$inputCode][] = [
                        'effective_year_month' => $effectiveYearMonth,
                        'staffing_divisor' => $divisor,
                    ];
                }

                $inputCodes = array_values(array_unique(array_merge(
                    array_keys($currentDivisorsByInputCode),
                    array_keys($historiesByInputCode)
                )));

                foreach ($months as $ym) {
                    $resolved[$ym] = [];
                    foreach ($inputCodes as $inputCode) {
                        $divisor = null;
                        foreach ($historiesByInputCode[$inputCode] ?? [] as $history) {
                            if (($history['effective_year_month'] ?? '') > $ym) {
                                break;
                            }

                            $divisor = (float) ($history['staffing_divisor'] ?? 0);
                        }

                        if ($divisor === null || $divisor <= 0) {
                            continue;
                        }

                        $resolved[$ym][$inputCode] = $divisor;
                    }
                }
            }
        }

        return $resolved;
    }

    private function assertFacilityAllowanceStaffAssignmentsTableExists(): void
    {
        if (Schema::hasTable('facility_allowance_staff_assignments')) {
            return;
        }

        throw new RuntimeException(
            'Required table "facility_allowance_staff_assignments" is missing for actuals addon staff assignments.'
        );
    }

    private function assertRequiredEvidenceInputCodeSchema(): void
    {
        if (!Schema::hasTable('evidence_input_codes')) {
            throw new RuntimeException('Required table "evidence_input_codes" is missing for staffing calculations.');
        }

        foreach (['input_code', 'certification_code', 'staffing_divisor', 'display_order', 'is_active'] as $column) {
            if (Schema::hasColumn('evidence_input_codes', $column)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Required column "evidence_input_codes.%s" is missing for staffing calculations.',
                $column
            ));
        }
    }

    /**
     * @param array<string, array<string, int|string|float|null>> $inputs
     * @param array<int, string> $months
     * @param array<string, array<string, float>> $monthlyDivisorsByInputCode
     * @return array<string, int>
     */
    private function buildMonthlyMinimumStaffingSlots(
        array $inputs,
        array $months,
        array $monthlyDivisorsByInputCode
    ): array {
        $minimumSlotsByMonth = array_fill_keys($months, 0);

        foreach ($months as $ym) {
            $sum = 0.0;
            $divisors = $monthlyDivisorsByInputCode[$ym] ?? [];
            foreach ($divisors as $inputCode => $divisor) {
                $count = (float) ($inputs[$inputCode][$ym] ?? 0);
                if ($count < 0 || $divisor <= 0) {
                    continue;
                }
                $sum += $count / $divisor;
            }

            $minimumSlotsByMonth[$ym] = max(0, (int) round($sum, 0, PHP_ROUND_HALF_UP));
        }

        return $minimumSlotsByMonth;
    }

    /**
     * 保育時間区分ごとの3次元配列 $values[code][duration][ym] を
     * duration を合算した2次元配列 $result[code][ym] に変換する。
     *
     * @param array<string, array<string, array<string, int|string|null>>> $values
     * @return array<string, array<string, int|string|null>>
     */
    private function sumValuesByDuration(array $values): array
    {
        $result = [];
        foreach ($values as $code => $durations) {
            foreach ($durations as $duration => $months) {
                if (!is_array($months)) {
                    // 旧形式（duration なし）の場合はそのまま
                    $result[$code][$duration] = $months;
                    continue;
                }
                foreach ($months as $ym => $val) {
                    $existing = (int) ($result[$code][$ym] ?? 0);
                    $result[$code][$ym] = (string) ($existing + (int) ($val ?? 0));
                }
            }
        }
        return $result;
    }

    /**
     * @param array<string, array<string, array<int, int>>> $addonStaffSelections
     * @param array<int, string> $months
     * @param array<string, int> $minimumSlotsByMonth
     */
    private function validateCap23StaffAssignmentsAgainstMinimum(
        array $addonStaffSelections,
        array $months,
        array $minimumSlotsByMonth
    ): void {
        if (!array_key_exists(self::CAP23_MIN_STAFFING_UI_CODE, EvidenceAddonStaffAssignmentCatalog::selectableDefinitions())) {
            return;
        }

        $messages = [];
        foreach ($months as $ym) {
            $required = (int) ($minimumSlotsByMonth[$ym] ?? 0);
            $selectedStaffIds = $addonStaffSelections[self::CAP23_MIN_STAFFING_UI_CODE][$ym] ?? [];
            $selectedCount = is_array($selectedStaffIds) ? count($selectedStaffIds) : 0;
            if ($selectedCount >= $required) {
                continue;
            }

            $messages['addon_staff.' . self::CAP23_MIN_STAFFING_UI_CODE . '.' . $ym] = sprintf(
                '%s の2・3号職員割当は最低必要人数 %d 人以上を選択してください。',
                $ym,
                $required
            );
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @return array<string, string>
     */
    private function addonStaffReadableSubsidyCodeMap(): array
    {
        $subsidyToUi = [];
        $legacyAliasesByUiCode = self::ADDON_STAFF_LEGACY_SUBSIDY_CODE_ALIASES;
        foreach (EvidenceAddonStaffAssignmentCatalog::selectableDefinitions() as $uiCode => $subsidyCode) {
            $subsidyToUi[$subsidyCode] = $uiCode;
            foreach (($legacyAliasesByUiCode[$uiCode] ?? []) as $legacySubsidyCode) {
                $subsidyToUi[$legacySubsidyCode] = $uiCode;
            }
        }

        return $subsidyToUi;
    }

    /**
     * @return array<int, string>
     */
    private function addonStaffDeletableSubsidyCodes(): array
    {
        $definitions = EvidenceAddonStaffAssignmentCatalog::selectableDefinitions();
        $codes = array_values($definitions);
        $legacyAliasesByUiCode = self::ADDON_STAFF_LEGACY_SUBSIDY_CODE_ALIASES;

        foreach (array_keys($definitions) as $uiCode) {
            foreach (($legacyAliasesByUiCode[$uiCode] ?? []) as $legacyCode) {
                $codes[] = $legacyCode;
            }
        }

        // 調整部分（減額）のコードも追加
        $codes[] = SubsidyCodes::BRANCH_FACILITY;
        $codes[] = SubsidyCodes::DIRECTOR_NOT_ASSIGNED;
        $codes[] = SubsidyCodes::DIRECTOR_NOT_ASSIGNED_TI12;
        $codes[] = SubsidyCodes::SATURDAY_CLOSURE;
        $codes[] = SubsidyCodes::CHRONIC_OVER_CAPACITY;

        return array_values(array_unique($codes));
    }

    private function ensureSyncSubsidyMasterRows(bool $includeMonthlyCodes): void
    {
        $codes = array_values(array_unique(array_merge(
            array_values(array_filter(
                $this->calculatedSubsidyCodes(),
                static fn (string $code): bool => $code !== SubsidyCodes::BASIC_UNIT_PRICE
            )),
            array_values(EvidenceAddonStaffAssignmentCatalog::candidateDefinitions())
        )));

        if ($includeMonthlyCodes) {
            $codes = array_merge($codes, $this->calculatedSubsidyCodes());
        }

        // 取得・補完・upsert を一括実行して N+1 クエリを避ける。
        app(SubsidyMasterSyncService::class)->sync(array_values(array_unique($codes)));
    }

    /**
     * @param array<int, string> $months
     * @param array<string, array<string, float>>|null $monthlyTotalsByCode
     * @return array<int, array{label: string, basic_amounts: array<string, float>, ti_amounts: array<string, float>}>
     */
    private function buildFixedAmountAddonRows(array $months, ?array $monthlyTotalsByCode): array
    {
        $rows = [];
        foreach ($this->evidenceFixedAmountAddonDefinitions() as $definition) {
            $baseCode = $definition['subsidy_code'];
            $tiCode = SubsidyCodes::ti12Code($baseCode);
            $rows[] = [
                'label' => $definition['label'],
                'basic_amounts' => $monthlyTotalsByCode[$baseCode] ?? array_fill_keys($months, 0.0),
                'ti_amounts' => $monthlyTotalsByCode[$tiCode] ?? array_fill_keys($months, 0.0),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, string> $months
     * @param array<string, array<string, float>>|null $monthlyTotalsByCode
     * @return array<int, array{label: string, basic_amounts: array<string, float>, ti_amounts: array<string, float>}>
     */
    private function buildSelectAmountAddonRows(array $months, ?array $monthlyTotalsByCode): array
    {
        $rows = [];
        foreach ($this->evidenceSelectAddonDefinitions() as $uiCode => $definition) {
            $baseCode = $uiCode; // ui_code == base_code for select addons
            $tiCode = SubsidyCodes::ti12Code($baseCode);
            $hasTi = false;
            foreach ($definition['options'] as $option) {
                if (($option['item_code_ti'] ?? null) !== null) {
                    $hasTi = true;
                    break;
                }
            }
            $rows[] = [
                'label' => $definition['label'],
                'basic_amounts' => $monthlyTotalsByCode[$baseCode] ?? array_fill_keys($months, 0.0),
                'ti_amounts' => $hasTi
                    ? ($monthlyTotalsByCode[$tiCode] ?? array_fill_keys($months, 0.0))
                    : array_fill_keys($months, 0.0),
            ];
        }

        return $rows;
    }

    /**
     * 年度設定は facility_settings を最優先し、未設定は facilities の固定値を初期値に使う。
     *
     * @return array{region_code: ?string, capacity: ?int, capacity_nursery: ?int, capacity_kindergarten: ?int, facility_type: ?string, is_branch: bool}
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
            // 認定こども園の認定別 利用定員（保育所は null＝capacity を使う）
            'capacity_nursery' => $setting?->capacity_nursery ?? $facility->capacity_nursery,
            'capacity_kindergarten' => $setting?->capacity_kindergarten ?? $facility->capacity_kindergarten,
            'facility_type' => $setting?->facility_type ?? $facility->facility_type,
            'is_branch' => (bool) ($setting?->is_branch ?? $facility->is_branch ?? false),
        ];
    }

    /**
     * facility_settings 保存用の payload を組み立てる。
     */
    private function buildAnnualSettingPayload(
        Facility $facility,
        ?FacilitySetting $existingSetting,
        ?string $regionCode,
        int $capacity,
        string $facilityType,
        ?bool $isBranch = null
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
            'facility_type' => $facilityType,
            'is_branch' => $isBranch,
            'open_time' => $openTime,
            'close_time' => $closeTime,
            'boundary_morning' => $existingSetting?->boundary_morning ?? $openTime,
            'boundary_core' => $existingSetting?->boundary_core ?? $openTime,
            'boundary_evening' => $existingSetting?->boundary_evening ?? $closeTime,
        ];
    }

    /**
     * 1つの subsidy_code について、12か月分の actual_amount を subsidy_actuals へ upsert する。
     */
    private function syncActual(
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
                'actual_amount' => (int) round((float) ($monthlyTotals[$ym] ?? 0)),
                'confirmed_source' => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SubsidyActual::query()->upsert(
            $rows,
            ['facility_id', 'year_month', 'subsidy_code'],
            ['fiscal_year', 'actual_amount', 'confirmed_source', 'updated_at']
        );
    }
}
