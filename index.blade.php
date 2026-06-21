@extends('layouts.app')

@section('title', '予算（根拠入力）')

@section('content')
    {{-- 根拠入力画面: 年度設定・区分ルール・年齢別人数を一体で入力する。 --}}
    <h1>予算（根拠入力）</h1>

    @if(session('success'))
        <div class="box">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="box">
            <p><strong>入力に問題があります：</strong></p>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 施設未作成 --}}
    @if(!$facility)
        <div class="box">
            <p>施設がありません。先に施設を作成してください。</p>
        </div>
        @return
    @endif

    {{-- フィルタ（施設・年度の切り替え） --}}
    <div class="box">
        <form method="GET" action="{{ route('evidence.budgets.inputs.index') }}">
            <label>施設</label>
            <select name="facility_id">
                @foreach($facilities as $f)
                    <option value="{{ $f->id }}" @selected($facilityId == $f->id)>
                        {{ $f->name }}（id: {{ $f->id }}）
                    </option>
                @endforeach
            </select>

            <label style="margin-left:12px;">年度</label>
            <input type="number" name="fiscal_year" value="{{ $fiscalYear }}" style="width:100px;">

            <button type="submit" style="margin-left:12px;">表示</button>
            <a href="{{ route('evidence.budgets.index', ['facility_id' => $facilityId, 'fiscal_year' => $fiscalYear]) }}" style="margin-left:12px;">予算を確認</a>
        </form>
    </div>

    <div class="box">
        {{-- 根拠入力フォーム本体 --}}
        <p class="muted">入力対象：2・3号 利用定員（0〜5歳）</p>

        <form method="POST" action="{{ route('evidence.budgets.inputs.update') }}">
            @csrf
            @method('PUT')

            <input type="hidden" name="facility_id" value="{{ $facilityId }}">
            <input type="hidden" name="fiscal_year" value="{{ $fiscalYear }}">

            <div style="margin: 0 0 12px 0;">
                <button type="submit">保存</button>
            </div>

            <table border="1" cellpadding="6" cellspacing="0" style="margin-bottom:12px;">
                {{-- 年度設定・区分ルールの入力ブロック --}}
                <tbody>
                <tr>
                    <th>地域区分</th>
                    <td>
                        <input type="text"
                               name="annual[region_code]"
                               value="{{ old('annual.region_code', $annualInput['region_code'] ?? '') }}"
                               placeholder="その他地域">
                    </td>
                    <th>定員</th>
                    <td>
                        <input type="number"
                               name="annual[capacity]"
                               value="{{ old('annual.capacity', $annualInput['capacity'] ?? 0) }}"
                               min="0"
                               step="1">
                    </td>
                    {{-- 認定別定員（こども園用。保育所は空欄＝従来の総定員を使う） --}}
                    <th>2・3号定員</th>
                    <td>
                        <input type="number" name="annual[capacity_nursery]"
                            value="{{ old('annual.capacity_nursery', $annualInput['capacity_nursery'] ?? '') }}"
                            min="0" step="1">
                    </td>
                    <th>1号定員</th>
                    <td>
                        <input type="number" name="annual[capacity_kindergarten]"
                            value="{{ old('annual.capacity_kindergarten', $annualInput['capacity_kindergarten'] ?? '') }}"
                            min="0" step="1">
                    </td>
                    <th>施設種別</th>
                    <td>
                        <input type="text"
                               name="annual[facility_type]"
                               value="{{ old('annual.facility_type', $annualInput['facility_type'] ?? '') }}">
                    </td>
                    <th>区分1</th>
                    <td>
                        <input type="number"
                               name="rule[category_1_percent]"
                               value="{{ old('rule.category_1_percent', isset($ruleInput['category_1_percent']) ? (float)$ruleInput['category_1_percent'] : '') }}"
                               min="0"
                               step="0.01"
                               style="width:90px;">
                    </td>
                    <th>区分2</th>
                    <td>
                        <input type="number"
                               name="rule[category_2_percent]"
                               value="{{ old('rule.category_2_percent', isset($ruleInput['category_2_percent']) ? (float)$ruleInput['category_2_percent'] : '') }}"
                               min="0"
                               step="0.01"
                               style="width:90px;">
                    </td>
                    <th>区分3A</th>
                    <td>
                        <input type="number"
                               name="rule[category_3a]"
                               value="{{ old('rule.category_3a', isset($ruleInput['category_3a']) ? (int)$ruleInput['category_3a'] : '') }}"
                               min="0"
                               step="1"
                               style="width:90px;">
                    </td>
                    <th>区分3B</th>
                    <td>
                        <input type="number"
                               name="rule[category_3b]"
                               value="{{ old('rule.category_3b', isset($ruleInput['category_3b']) ? (int)$ruleInput['category_3b'] : '') }}"
                               min="0"
                               step="1"
                               style="width:90px;">
                    </td>
                </tr>
                </tbody>
            </table>

            <table border="1" cellpadding="6" cellspacing="0">
                {{-- 0〜5歳の月次人数入力ブロック --}}
                <thead>
                    <tr>
                        <th>認定区分</th>
                        <th>項目</th>
                        @foreach($months as $idx => $ym)
                            <th>
                                <div>{{ $ym }}</div>
                                @if($idx > 0)
                                    <button type="button"
                                            class="copy-prev-month"
                                            data-source-month="{{ $months[$idx - 1] }}"
                                            data-target-month="{{ $ym }}">
                                        前月コピー
                                    </button>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $code => $label)
                        <tr>
                            @if($loop->first)
                                <td rowspan="{{ count($rows) }}">2・3号</td>
                            @endif
                            <th>{{ $label }}</th>
                            @foreach($months as $ym)
                                @php
                                    $v = $values[$code][$ym] ?? '';
                                @endphp
                                <td>
                                    <input type="number"
                                           name="inputs[{{ $code }}][{{ $ym }}]"
                                           value="{{ old("inputs.$code.$ym", $v) }}"
                                           data-code="{{ $code }}"
                                           data-ym="{{ $ym }}"
                                           min="0"
                                           step="1"
                                           style="width:90px;">
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table border="1" cellpadding="6" cellspacing="0" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>各種加算</th>
                        @foreach($months as $idx => $ym)
                            <th>
                                <div>{{ $ym }}</div>
                                @if($idx > 0)
                                    <button type="button"
                                            class="copy-prev-addon-month"
                                            data-source-month="{{ $months[$idx - 1] }}"
                                            data-target-month="{{ $ym }}">
                                        前月コピー
                                    </button>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    {{-- 加算の入力欄：DBに登録された加算を全部ループで並べる（旧実装は5つ決め打ちだった）。
                         実績入力と同じ $unifiedAddonDefinitions（checkbox型＋select型を統合した一覧）を回す。
                         なぜ動的か：加算が制度で増減してもBladeを直さず、DB（subsidy_master）側だけで反映できるようにするため。 --}}
                    @foreach($unifiedAddonDefinitions as $addonDef)
                        {{-- 3月だけ有効な加算（決算期のみ）は、この表では扱わないので飛ばす --}}
                        @if($addonDef['is_march_only']) @continue @endif
                        @php
                            $uiCode = $addonDef['ui_code'];   // 例 'AGE4PLUS_STAFFING'（保存時のキーになる）
                            $addonType = $addonDef['type'];   // 'checkbox' か 'select'
                        @endphp
                        <tr>
                            <th>{{ $addonDef['label'] }}</th>{{-- 加算名はDBの定義から自動表示 --}}
                            @foreach($months as $ym)
                                <td style="text-align:center;">
                                    @if($addonType === 'checkbox')
                                        {{-- チェック型：その加算が「ある／ない」を 1/0 で持つ --}}
                                        @php
                                            // 初期表示：差し戻し値(old) → DB保存値(is_selected) の順で決める
                                            $checked = old("addons.$uiCode.$ym");
                                            if ($checked === null) {
                                                $checked = (($addonValues[$uiCode][$ym]['is_selected'] ?? false) ? '1' : null);
                                            }
                                        @endphp
                                        <input type="checkbox"
                                               name="addons[{{ $uiCode }}][{{ $ym }}]"
                                               value="1"
                                               data-addon-code="{{ $uiCode }}"
                                               data-addon-ym="{{ $ym }}"
                                               @checked((string)$checked === '1')>
                                    @elseif($addonType === 'select')
                                        {{-- 選択型：いくつかの選択肢から1つ選ぶ。保存されるのは選んだコード(input_value) --}}
                                        @php
                                            // 初期表示：差し戻し値(old) → DB保存値(input_value) の順で決める
                                            $currentValue = old("addons.$uiCode.$ym") ?? ($addonValues[$uiCode][$ym]['input_value'] ?? '');
                                        @endphp
                                        <select name="addons[{{ $uiCode }}][{{ $ym }}]" style="width:160px;">
                                            <option value="">なし</option>
                                            @foreach($addonDef['options'] as $optionCode => $option)
                                                <option value="{{ $optionCode }}" @selected((string)$currentValue === (string)$optionCode)>
                                                    {{ $option['name'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach

                    {{-- チーム保育加配加算は number 型＝専用の人数入力。number型は $unifiedAddonDefinitions に
                         含まれない（buildUnifiedAddonDefinitions で除外）ので、ループの外で個別に描画する。 --}}
                    <tr>
                        <th>チーム保育加配加算</th>
                        @foreach($months as $ym)
                            <td>
                                @php
                                    $numberValue = old("addons.TEAM_STAFFING_COUNT.$ym");
                                    if ($numberValue === null) {
                                        $numberValue = $addonValues['TEAM_STAFFING_COUNT'][$ym]['input_value'] ?? '';
                                    }
                                @endphp
                                <input type="number"
                                       name="addons[TEAM_STAFFING_COUNT][{{ $ym }}]"
                                       value="{{ $numberValue }}"
                                       data-addon-code="TEAM_STAFFING_COUNT"
                                       data-addon-ym="{{ $ym }}"
                                       min="0"
                                       step="1"
                                       style="width:90px;">
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
            @if(!empty($calcErrors))
                {{-- 単価計算に必要な条件不足時のエラー表示 --}}
                <div class="box">
                    <p><strong>計算できません：</strong></p>
                    <ul>
                        @foreach($calcErrors as $msg)
                            <li>{{ $msg }}</li>
                        @endforeach
                    </ul>
                </div>
            @else
                {{-- 単価計算結果の月次表示 --}}
        <div class="box">
            <h2>計算結果</h2>
            <p class="muted">地域区分：{{ $regionCode }}</p>

            <table border="1" cellpadding="6" cellspacing="0">
                <thead>
                <tr>
                    <th rowspan="2">月</th>
                    <th colspan="2">年齢配置基準</th>
                    <th colspan="2">チーム保育加算</th>
                    <th colspan="2">4歳以上児年齢別加算</th>
                    <th colspan="2">3歳児年齢別加算</th>
                    <th colspan="2">1歳児年齢別加算</th>
                </tr>
                <tr>
                    <th>基本分</th>
                    <th>区分1・2</th>
                    <th>基本分</th>
                    <th>区分1・2</th>
                    <th>基本分</th>
                    <th>区分1・2</th>
                    <th>基本分</th>
                    <th>区分1・2</th>
                    <th>基本分</th>
                    <th>区分1・2</th>
                </tr>
                </thead>
                <tbody>
                @foreach($months as $ym)
                    <tr>
                        <td>{{ $ym }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyTotals[$ym] ?? 0)) }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyTotalsTi12[$ym] ?? 0)) }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyTeamCare[$ym] ?? 0)) }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyTeamCareTi12[$ym] ?? 0)) }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge4[$ym] ?? 0)) }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge4Ti12[$ym] ?? 0)) }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge3[$ym] ?? 0)) }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge3Ti12[$ym] ?? 0)) }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge1[$ym] ?? 0)) }}</td>
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge1Ti12[$ym] ?? 0)) }}</td>
                    </tr>
                @endforeach
                {{-- 処遇改善等加算（区分3） --}}
                @if(collect($monthlyCat3 ?? [])->some(fn ($v) => (float) $v !== 0.0))
                <tr>
                    <th>処遇改善等加算（区分3）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int) round($monthlyCat3[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                @endif
                {{-- 施設機能強化推進費（3月のみ） --}}
                @if(collect($monthlyFacilityCapability ?? [])->some(fn ($v) => (float) $v !== 0.0))
                <tr>
                    <th>施設機能強化推進費</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int) round($monthlyFacilityCapability[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                @endif
                </tbody>
            </table>
        </div>
            @endif
        </form>
    </div>

    <script>
        // 「前月コピー」ボタン:
        // 同じ年齢コードの前月値を対象月へ一括反映する。
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.copy-prev-month');
            if (!btn) return;

            const sourceMonth = btn.dataset.sourceMonth;
            const targetMonth = btn.dataset.targetMonth;
            if (!sourceMonth || !targetMonth) return;

            const targetInputs = document.querySelectorAll(`input[data-ym="${targetMonth}"][data-code]`);
            targetInputs.forEach((targetInput) => {
                const code = targetInput.dataset.code;
                const sourceInput = document.querySelector(`input[data-ym="${sourceMonth}"][data-code="${code}"]`);
                if (sourceInput) {
                    targetInput.value = sourceInput.value;
                }
            });
        });

        // 各種加算テーブルの「前月コピー」ボタン:
        // 同じ加算コードの前月値を対象月へ一括反映する。
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.copy-prev-addon-month');
            if (!btn) return;

            const sourceMonth = btn.dataset.sourceMonth;
            const targetMonth = btn.dataset.targetMonth;
            if (!sourceMonth || !targetMonth) return;

            const targetInputs = document.querySelectorAll(`[data-addon-ym="${targetMonth}"][data-addon-code]`);
            targetInputs.forEach((targetInput) => {
                const code = targetInput.dataset.addonCode;
                const sourceInput = document.querySelector(`[data-addon-ym="${sourceMonth}"][data-addon-code="${code}"]`);
                if (!sourceInput) return;

                if (targetInput.type === 'checkbox') {
                    targetInput.checked = sourceInput.checked;
                } else {
                    targetInput.value = sourceInput.value;
                }
            });
        });
    </script>
@endsection
