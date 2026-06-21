<?php

namespace App\Http\Controllers\Evidence\Concerns;

use App\Support\EvidenceAddonCatalog;
use App\Support\SubsidyCodes;

/**
 * Controller から addon 設定を扱うための薄いアクセサ trait。
 *
 * 役割:
 * - Catalog から取得した定義を「1リクエスト内で使い回す」ためのキャッシュを持つ
 * - Controller 側は DB スキーマや fallback の存在を意識せずに
 *   `evidenceAddonDefinitions()` / `evidenceAgeSpecificAddonRules()` を呼べる
 *
 * 補足:
 * - 実データの取得元・正規化ルールは EvidenceAddonCatalog に集約される
 * - この trait は「呼び出しやすくする」「重複計算を避ける」ための層
 */
trait ResolvesEvidenceAddonRules
{
    /**
     * @var array<string, array{
     *   subsidy_code: string,
     *   type: string,
     *   label: string,
     *   basic_item_code: ?string,
     *   ti_item_code: ?string,
     *   rate_c_item_code: ?string
     * }>|null
     */
    private ?array $resolvedEvidenceAddonDefinitions = null;

    /**
     * @var array<string, array{
     *   subsidy_code: string,
     *   type: string,
     *   label: string,
     *   basic_item_code: ?string,
     *   ti_item_code: ?string,
     *   rate_c_item_code: ?string
     * }>|null
     */
    private ?array $resolvedEvidenceFixedAmountAddonDefinitions = null;

    /**
     * @var array<string, array{input_ages: array<int, string>, item_codes: array<int, string>, result_key: string, result_ti_key: string}>|null
     */
    private ?array $resolvedEvidenceAgeSpecificAddonRules = null;

    /**
     * このリクエストで扱う施設種別コード（HOIKUEN / KODOMOEN 等）。
     * null＝全件（従来動作）。加算メニューを施設種別で出し分けるために使う。
     */
    private ?string $evidenceFacilityTypeCode = null;

    /**
     * 加算メニュー/ルールを「この施設種別のものだけ」に絞るためのコードを設定する。
     * 画面表示・保存処理の冒頭で施設種別を解決したら1度呼ぶ（trait メソッド使用より前）。
     * 種別が変わったら解決済みキャッシュを破棄し、別種別のメニューが残らないようにする。
     */
    protected function useEvidenceFacilityTypeCode(?string $facilityTypeCode): void
    {
        if ($facilityTypeCode === $this->evidenceFacilityTypeCode) {
            return;
        }

        $this->evidenceFacilityTypeCode = $facilityTypeCode;
        $this->resolvedEvidenceAddonDefinitions = null;
        $this->resolvedEvidenceFixedAmountAddonDefinitions = null;
        $this->resolvedEvidenceAgeSpecificAddonRules = null;
    }

    /**
     * 画面で扱う addon 定義（UIコード -> 補助金コード/入力タイプ）を返す。
     *
     * 目的:
     * - Controller から addon の種類（checkbox/number）を判断できるようにする
     * - 保存時に「UIコードがどの subsidy_code に対応するか」を引けるようにする
     *
     * 挙動:
     * - 初回呼び出し: Catalog から取得してプロパティに保持
     * - 2回目以降: プロパティ値をそのまま返す（再取得しない）
     *
     * @return array<string, array{
     *   subsidy_code: string,
     *   type: string,
     *   label: string,
     *   basic_item_code: ?string,
     *   ti_item_code: ?string,
     *   rate_c_item_code: ?string
     * }>
     */
    protected function evidenceAddonDefinitions(): array
    {
        if ($this->resolvedEvidenceAddonDefinitions !== null) {
            return $this->resolvedEvidenceAddonDefinitions;
        }

        $this->resolvedEvidenceAddonDefinitions = EvidenceAddonCatalog::addonDefinitions($this->evidenceFacilityTypeCode);

        return $this->resolvedEvidenceAddonDefinitions;
    }

    /**
     * @return array<string, array{
     *   subsidy_code: string,
     *   type: string,
     *   label: string,
     *   basic_item_code: ?string,
     *   ti_item_code: ?string,
     *   rate_c_item_code: ?string
     * }>
     */
    protected function evidenceFixedAmountAddonDefinitions(): array
    {
        if ($this->resolvedEvidenceFixedAmountAddonDefinitions !== null) {
            return $this->resolvedEvidenceFixedAmountAddonDefinitions;
        }

        $this->resolvedEvidenceFixedAmountAddonDefinitions = EvidenceAddonCatalog::fixedAmountAddonDefinitions($this->evidenceFacilityTypeCode);

        return $this->resolvedEvidenceFixedAmountAddonDefinitions;
    }

    /**
     * 年齢別加算 addon の計算ルールを返す。
     *
     * 目的:
     * - 年齢コードごとに、どの official_price item_code を使って
     *   どの月次結果キーへ加算するかを解決する
     *
     * 挙動:
     * - 初回呼び出し: Catalog から取得してプロパティに保持
     * - 2回目以降: プロパティ値をそのまま返す（再取得しない）
     *
     * @return array<string, array{input_ages: array<int, string>, item_codes: array<int, string>, result_key: string, result_ti_key: string}>
     */
    protected function evidenceAgeSpecificAddonRules(): array
    {
        if ($this->resolvedEvidenceAgeSpecificAddonRules !== null) {
            return $this->resolvedEvidenceAgeSpecificAddonRules;
        }

        $this->resolvedEvidenceAgeSpecificAddonRules = EvidenceAddonCatalog::ageSpecificAddonRules($this->evidenceFacilityTypeCode);

        return $this->resolvedEvidenceAgeSpecificAddonRules;
    }

    /**
     * 年齢別加算 addon で参照する official_price の item_code 一覧を返す。
     *
     * 目的:
     * - 単価取得クエリの `whereIn(item_code, ...)` に渡す候補を作る
     * - ルールごとの item_code 配列をフラット化して重複排除する
     *
     * 返却値の性質:
     * - 空文字は除外
     * - 同じコードが複数ルールに出ても1件に集約
     * - 順序は「最初に登場した順」を維持
     *
     * @return array<int, string>
     */
    /**
     * select 型 addon 定義（オプション付き）を返す。
     *
     * @return array<string, array{
     *   ui_code: string,
     *   label: string,
     *   type: string,
     *   display_order: int,
     *   is_march_only: bool,
     *   options: array<string, array{code: string, name: string, item_code_basic: ?string, item_code_ti: ?string, item_code_c: ?string}>
     * }>
     */
    protected function evidenceSelectAddonDefinitions(): array
    {
        return EvidenceAddonCatalog::selectAddonDefinitions($this->evidenceFacilityTypeCode);
    }

    /**
     * @return array<string, array{
     *   subsidy_code: string,
     *   type: string,
     *   label: string,
     *   basic_item_code: ?string,
     *   ti_item_code: ?string,
     *   rate_c_item_code: ?string
     * }>
     */
    protected function checkboxAddonDefinitions(): array
    {
        $definitions = [];
        foreach ($this->evidenceAddonDefinitions() as $uiCode => $definition) {
            if (($definition['type'] ?? null) !== 'checkbox') {
                continue;
            }

            $definitions[$uiCode] = $definition;
        }

        return $definitions;
    }

    /**
     * checkbox 型と select 型の addon 定義を evidence_display_order 順に統合して返す。
     *
     * チーム保育加配加算（number 型）は専用UIがあるため除外する。
     *
     * @param array<string, array{subsidy_code: string, type: string, label: string}> $checkboxDefs
     * @param array<string, array{ui_code: string, label: string, type: string, display_order: int, is_march_only: bool, options: array}> $selectDefs
     * @return array<int, array{ui_code: string, type: string, label: string, display_order: int, is_march_only: bool, options: array|null, definition: array}>
     */
    protected function buildUnifiedAddonDefinitions(array $checkboxDefs, array $selectDefs): array
    {
        $unified = [];

        // checkbox 型の evidence_display_order と is_only_march_toggleable を DB から取得
        $checkboxMeta = \App\Models\SubsidyMaster::query()
            ->whereIn('code', array_map(fn ($d) => $d['subsidy_code'], $checkboxDefs))
            ->get(['code', 'evidence_display_order', 'is_only_march_toggleable'])
            ->keyBy('code');

        foreach ($checkboxDefs as $uiCode => $definition) {
            // number 型（チーム保育加配加算）は専用UIで別途表示するため除外
            if (($definition['type'] ?? '') === 'number') {
                continue;
            }
            $subsidyCode = $definition['subsidy_code'];
            $meta = $checkboxMeta[$subsidyCode] ?? null;
            $unified[] = [
                'ui_code' => $uiCode,
                'type' => 'checkbox',
                'label' => $definition['label'],
                'display_order' => (int) ($meta?->evidence_display_order ?? 0),
                'is_march_only' => (bool) ($meta?->is_only_march_toggleable ?? false),
                'options' => null,
                'definition' => $definition,
            ];
        }

        // select 型
        foreach ($selectDefs as $uiCode => $definition) {
            $unified[] = [
                'ui_code' => $uiCode,
                'type' => 'select',
                'label' => $definition['label'],
                'display_order' => $definition['display_order'],
                'is_march_only' => $definition['is_march_only'] ?? false,
                'options' => $definition['options'] ?? [],
                'definition' => $definition,
            ];
        }

        // ソート: 3月のみ加算を下にまとめ、同グループ内は display_order 順
        usort($unified, static function (array $a, array $b): int {
            $aMarch = $a['is_march_only'] ? 1 : 0;
            $bMarch = $b['is_march_only'] ? 1 : 0;
            if ($aMarch !== $bMarch) {
                return $aMarch <=> $bMarch;
            }
            return $a['display_order'] <=> $b['display_order'];
        });

        return $unified;
    }


    protected function evidenceAgeSpecificAddonItemCodes(): array
    {
        $codes = [];
        foreach ($this->evidenceAgeSpecificAddonRules() as $rule) {
            foreach ($rule['item_codes'] as $itemCode) {
                $normalized = trim((string) $itemCode);
                if ($normalized === '') {
                    continue;
                }
                $codes[$normalized] = $normalized;
            }
        }

        return array_values($codes);
    }

    /**
     * 月次計算機（MonthlyActualsCalculator）が金額を出力する subsidy_code を、
     * この施設種別ぶん「全部」集めて返す。subsidy_actuals / subsidy_budgets への
     * 保存ループや subsidy_master 同期は、このリストを基準に回す。
     *
     * 実績・見込で共通なのでトレイトに置く（[[feedback_propose_long_term_rebuilds]] / 学び87）。
     * 中身は $this->evidenceFixedAmountAddonDefinitions() など同じトレイトの仲間メソッドに依存し、
     * それらは施設種別フィルタ（$this->evidenceFacilityTypeCode）で結果が変わる＝呼ぶ前に
     * useEvidenceFacilityTypeCode() を済ませておくこと（こども園なら1号加算も自動で含まれる）。
     *
     * 集める内訳:
     * 1. INPUT_LINKED_CODES … 基本分・年齢別・チーム保育（人数連動の定番10コード）
     * 2. 定額加算 … 各加算の base コード。TI(処遇改善)とC(率)の単価が両方揃う加算だけ TI コードも追加
     * 3. 選択型加算 … 選んだ肢の base コード。TIオプションを持つ加算だけ TI コードも追加
     * 4. FOOD_FEE_EXEMPTION … 副食費徴収免除加算（pass-through。TIは無いので base のみ）
     * 5. TREATMENT_IMPROVEMENT_CAT3 … 処遇改善等加算【区分3】。加算トグルでなく人数A/Bから
     *    計算するため加算定義リストに現れない＝計算結果には含まれるので明示追加が要る
     *
     * @return array<int, string>
     */
    protected function calculatedSubsidyCodes(): array
    {
        // まず人数連動の定番コードを初期値に積む（空配列でなく10個入りでスタート）。
        $codes = SubsidyCodes::INPUT_LINKED_CODES;

        // 定額加算：base を積み、処遇改善ぶん（TI）の単価が揃う加算だけ TI コードも積む。
        foreach ($this->evidenceFixedAmountAddonDefinitions() as $definition) {
            $baseCode = $definition['subsidy_code'];
            $codes[] = $baseCode;
            if (($definition['ti_item_code'] ?? null) !== null && ($definition['rate_c_item_code'] ?? null) !== null) {
                $codes[] = SubsidyCodes::ti12Code($baseCode);
            }
        }

        // 選択型加算：ui_code がそのまま base コード。いずれかの肢が TI 単価を持てば TI コードも積む。
        foreach ($this->evidenceSelectAddonDefinitions() as $uiCode => $definition) {
            $baseCode = $uiCode; // ui_code == base_code for select addons
            $codes[] = $baseCode;
            $hasTi = false;
            foreach ($definition['options'] as $option) {
                if (($option['item_code_ti'] ?? null) !== null) {
                    $hasTi = true;
                    break;
                }
            }
            if ($hasTi) {
                $codes[] = SubsidyCodes::ti12Code($baseCode);
            }
        }

        // 副食費徴収免除加算（pass-through）も保存対象に含める（TIは無いので基本コードのみ）。
        $codes[] = SubsidyCodes::FOOD_FEE_EXEMPTION;

        // 処遇改善等加算【区分3】は加算トグルでなく人数A/B(category3a/b)から計算するため、
        // 上記の加算定義リストに現れない。計算結果(buildMonthlyTotalsForSync)には含まれるので
        // 保存対象へ明示追加する（これが無いと区分3が保存されない）。
        $codes[] = SubsidyCodes::TREATMENT_IMPROVEMENT_CAT3;

        // 同じコードが複数経路で入りうるので重複排除し、添字を 0..n に振り直して返す。
        return array_values(array_unique($codes));
    }
}
