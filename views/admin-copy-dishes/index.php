<?php
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\JqueryAsset;
use kartik\select2\Select2;
use aryelds\sweetalert\SweetAlert;

/* @var $this yii\web\View */
/* @var $merchantList array   [merchant_id => restaurant_name] */
/* @var $outletList array     [outlet_id => "Outlet — Parent · Address"] (source AND target pickers) */
/* @var $adminMerchantId int  admin's own merchant_id (default source) */

foreach (Yii::$app->session->getAllFlashes() as $message) {
    echo SweetAlert::widget(['options' => [
        'title'             => $message['title']             ?? 'Info',
        'text'              => Html::encode($message['text'] ?? ''),
        'type'              => $message['type']              ?? 'info',
        'timer'             => $message['timer']             ?? 5000,
        'showConfirmButton' => $message['showConfirmButton'] ?? true,
    ]]);
}

$this->title = 'Copy / Delete Dishes';
$this->params['breadcrumbs'][] = $this->title;

JqueryAsset::register($this);
?>

<style>
.step-card { border-left: 4px solid #00a65a; background: #fff; border-radius: 4px; padding: 18px 20px; margin-bottom: 22px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.step-card h4 { margin-top: 0; color: #00a65a; font-size: 15px; font-weight: 700; }
.stat-box { border-radius: 6px; padding: 14px 18px; color: #fff; text-align: center; }
.stat-box .num  { font-size: 28px; font-weight: 700; line-height: 1; }
.stat-box .lbl  { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; margin-top: 4px; }
.bg-blue   { background: #0073b7; }
.bg-green  { background: #00a65a; }
.bg-orange { background: #f39c12; }
.bg-red    { background: #dd4b39; }
#dish-table th { background: #f4f4f4; }
#dish-table td, #dish-table th { vertical-align: middle !important; }
.exists-badge  { background: #00a65a; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: 6px; }
.missing-badge { background: #f39c12; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: 6px; }
.cat-pill      { background: #ecf0f5; color: #555; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 6px; }
.filter-bar    { background: #f9fafc; border: 1px solid #e3e6ec; border-radius: 4px; padding: 12px; margin-bottom: 10px; }
.mode-toggle   { display:inline-block; }
.mode-toggle .btn { min-width: 130px; }
.mode-toggle .btn.active { box-shadow: inset 0 2px 4px rgba(0,0,0,.15); }
</style>

<div class="admin-copy-dishes">
    <h1 style="margin-bottom:20px;"><i class="fa fa-copy"></i> <?= Html::encode($this->title) ?></h1>
    <p class="text-muted" style="margin-top:-12px; margin-bottom:20px;">
        <strong>Copy mode:</strong> add dishes from a source to the target. Dishes with the same name (case-insensitive) at the target are skipped.<br>
        <strong>Delete mode:</strong> remove specific dishes that currently exist at the target.
    </p>

    <?php if (!empty($copyUndo) && !empty($copyUndo['dish_ids'])): ?>
        <div class="step-card" style="border-left-color:#dd4b39; background:#fff7f6;">
            <h4 style="color:#dd4b39;"><i class="fa fa-undo"></i> Undo Last Copy</h4>
            <p style="margin-bottom:10px;">
                Last copy: <b><?= (int)$copyUndo['count'] ?></b> dish(es) to
                <b><?= Html::encode($copyUndo['target_label']) ?></b>
                <span class="text-muted">(<?= Html::encode($copyUndo['when']) ?>)</span>.
                This removes only the dishes that copy created.
            </p>
            <?= Html::beginForm(['undo-last'], 'post', ['style' => 'display:inline']) ?>
                <button type="submit" class="btn btn-danger"
                    onclick="return confirm('Remove the <?= (int)$copyUndo['count'] ?> dish(es) created by the last copy? This cannot be undone.');">
                    <i class="fa fa-undo"></i> Undo last copy (remove <?= (int)$copyUndo['count'] ?> dishes)
                </button>
            <?= Html::endForm() ?>
        </div>
    <?php endif; ?>

    <?= Html::beginForm(['execute'], 'post', ['id' => 'copy-form']) ?>
    <input type="hidden" name="source_merchant_id" id="h_source_merchant_id" value="<?= (int)$adminMerchantId ?>">
    <input type="hidden" name="source_type"        id="h_source_type"        value="merchant">
    <input type="hidden" name="target_id"          id="h_target_id"          value="">
    <input type="hidden" name="target_type"        id="h_target_type"        value="merchant">
    <input type="hidden" name="mode"               id="h_mode"               value="copy">

    <!-- MODE TOGGLE -->
    <div class="step-card" style="border-left-color:#0073b7;">
        <h4 style="color:#0073b7;"><i class="fa fa-cog"></i> Mode</h4>
        <div class="mode-toggle btn-group" role="group">
            <button type="button" class="btn btn-success active" id="mode-copy-btn">
                <i class="fa fa-copy"></i> Copy Dishes
            </button>
            <button type="button" class="btn btn-default" id="mode-delete-btn">
                <i class="fa fa-trash"></i> Delete Dishes
            </button>
        </div>
    </div>

    <!-- STEP 1 (Source) — hidden in delete mode -->
    <div class="step-card" id="step-source">
        <h4><span class="badge" style="background:#0073b7;margin-right:6px;">1</span> Source (Copy From)</h4>
        <div class="row">
            <div class="col-sm-3">
                <label>Source Type</label>
                <select class="form-control" id="source_type_ui">
                    <option value="merchant">Merchant</option>
                    <option value="outlet">Outlet</option>
                </select>
            </div>

            <div class="col-sm-4" id="source-merchant-wrap">
                <label>Select Source Merchant</label>
                <?= Select2::widget([
                    'name'          => '_source_merchant_ui',
                    'id'            => 'source_merchant_ui',
                    'value'         => $adminMerchantId,
                    'data'          => $merchantList,
                    'options'       => ['placeholder' => 'Select merchant…', 'id' => 'source_merchant_ui'],
                    'pluginOptions' => ['allowClear' => true],
                ]) ?>
            </div>

            <div class="col-sm-6" id="source-outlet-wrap" style="display:none">
                <label>Select Source Outlet</label>
                <?= Select2::widget([
                    'name'          => '_source_outlet_ui',
                    'id'            => 'source_outlet_ui',
                    'data'          => $outletList,
                    'options'       => ['placeholder' => 'Search any outlet…', 'id' => 'source_outlet_ui'],
                    'pluginOptions' => ['allowClear' => true],
                ]) ?>
                <p class="help-block" style="margin-top:4px;">All outlets are listed, labelled with their parent merchant and address &mdash; you can search by any of them.</p>
            </div>

            <div class="col-sm-3" id="source-stat-wrap" style="display:none">
                <div class="stat-box bg-blue" style="margin-top:24px;">
                    <div class="num" id="stat-source-total">0</div>
                    <div class="lbl">Dishes in Source</div>
                </div>
            </div>
        </div>
    </div>

    <!-- STEP 2 (Target) -->
    <div class="step-card" id="step2" style="<?= $adminMerchantId ? '' : 'display:none' ?>">
        <h4>
            <span class="badge" style="background:#0073b7;margin-right:6px;">2</span>
            <span id="step2-title">Target (Copy To)</span>
        </h4>
        <div class="row">
            <div class="col-sm-3">
                <label>Target Type</label>
                <select class="form-control" id="target_type_ui">
                    <option value="merchant">Merchant</option>
                    <option value="outlet">Outlet</option>
                </select>
            </div>

            <div class="col-sm-4" id="target-merchant-wrap">
                <label>Select Target Merchant</label>
                <?= Select2::widget([
                    'name'          => '_target_merchant_ui',
                    'id'            => 'target_merchant_ui',
                    'data'          => $merchantList,
                    'options'       => ['placeholder' => 'Select merchant…', 'id' => 'target_merchant_ui'],
                    'pluginOptions' => ['allowClear' => true],
                ]) ?>
            </div>

            <div class="col-sm-6" id="target-outlet-wrap" style="display:none">
                <label>Select Target Outlet</label>
                <?= Select2::widget([
                    'name'          => '_target_outlet_ui',
                    'id'            => 'target_outlet_ui',
                    'data'          => $outletList,
                    'options'       => ['placeholder' => 'Search any outlet…', 'id' => 'target_outlet_ui'],
                    'pluginOptions' => ['allowClear' => true],
                ]) ?>
                <p class="help-block" style="margin-top:4px;">All outlets are listed, labelled with their parent merchant and address &mdash; you can search by any of them.</p>
            </div>
        </div>

        <div class="row" id="target-stats" style="display:none; margin-top:14px;">
            <div class="col-sm-3">
                <div class="stat-box bg-green">
                    <div class="num" id="stat-already">0</div>
                    <div class="lbl"><span id="stat-already-lbl">Already Exists</span></div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="stat-box bg-orange">
                    <div class="num" id="stat-missing">0</div>
                    <div class="lbl"><span id="stat-missing-lbl">Missing / New</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- STEP 3 -->
    <div class="step-card" id="step3" style="display:none">
        <h4><span class="badge" style="background:#0073b7;margin-right:6px;">3</span> <span id="step3-title">Select Dishes to Copy</span></h4>

        <div id="loading-dishes" style="display:none; color:#888; margin-top:8px;">
            <i class="fa fa-spinner fa-spin"></i> Loading dishes…
        </div>

        <div id="dish-selection-wrap" style="display:none">
            <div class="filter-bar">
                <div class="row">
                    <div class="col-sm-5">
                        <label style="font-size:12px;margin-bottom:4px;">Search dish name</label>
                        <div style="position:relative;">
                            <i class="fa fa-search" style="position:absolute;left:10px;top:10px;color:#999;"></i>
                            <input type="text" class="form-control" id="dish-search" placeholder="Type to filter…" style="padding-left:30px;">
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <label style="font-size:12px;margin-bottom:4px;">Filter by category</label>
                        <select class="form-control" id="dish-category-filter">
                            <option value="">— All categories —</option>
                        </select>
                    </div>
                    <div class="col-sm-3" id="status-filter-wrap">
                        <label style="font-size:12px;margin-bottom:4px;">Show</label>
                        <select class="form-control" id="dish-status-filter">
                            <option value="all">All dishes</option>
                            <option value="missing">Only missing</option>
                            <option value="exists">Only already at target</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <button type="button" class="btn btn-xs btn-default" id="btn-select-missing">
                        <i class="fa fa-magic"></i> <span id="btn-select-missing-lbl">Select All Missing</span>
                    </button>
                    <button type="button" class="btn btn-xs btn-default" id="btn-select-all">
                        <i class="fa fa-check-square-o"></i> Select All (in view)
                    </button>
                    <button type="button" class="btn btn-xs btn-warning" id="btn-clear">
                        <i class="fa fa-times"></i> Clear
                    </button>
                    <span style="margin-left:12px;" class="text-muted" id="selected-count-label"></span>
                    <span style="margin-left:8px;" class="text-muted" id="filter-count-label"></span>
                </div>
            </div>

            <div style="max-height:400px; overflow-y:auto; border:1px solid #ddd; border-radius:4px;">
                <table class="table table-condensed table-hover" id="dish-table" style="margin-bottom:0">
                    <thead>
                        <tr>
                            <th style="width:36px;"><input type="checkbox" id="chk-all-top"></th>
                            <th>Dish Name</th>
                            <th style="width:160px;">Category</th>
                            <th style="width:140px;" id="status-col-th">Status at Target</th>
                        </tr>
                    </thead>
                    <tbody id="dish-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- STEP 4 -->
    <div id="step4" style="display:none; margin-bottom:30px;">
        <button type="submit" class="btn btn-lg" id="submit-btn">
            <span id="submit-btn-label"><i class="fa fa-copy"></i> Copy Selected Dishes</span>
        </button>
        &nbsp;
        <a href="<?= Url::to(['index']) ?>" class="btn btn-default btn-lg">Cancel</a>
    </div>

    <?= Html::endForm() ?>
</div>

<?php
$urlGetDishes       = Url::to(['get-dishes']);
$urlGetTargetDishes = Url::to(['get-target-dishes']);
$urlGetOutlets      = Url::to(['get-outlets']);
$urlExecute         = Url::to(['execute']);
$urlDelete          = Url::to(['delete-dishes']);
$adminMidJs         = (int)$adminMerchantId;

$js = <<<JS
(function () {
    function boot(\$) {
        var mode       = 'copy';   // 'copy' | 'delete'
        var allDishes  = [];
        var selectedIds = {};
        var sourceMid  = {$adminMidJs};
        var sourceType = 'merchant';   // 'merchant' | 'outlet'
        var targetId   = 0;
        var targetType = 'merchant';

        var urlGetDishes       = '{$urlGetDishes}';
        var urlGetTargetDishes = '{$urlGetTargetDishes}';
        var urlGetOutlets      = '{$urlGetOutlets}';
        var urlExecute         = '{$urlExecute}';
        var urlDelete          = '{$urlDelete}';

        function updateHiddens() {
            \$('#h_source_merchant_id').val(sourceMid);
            \$('#h_source_type').val(sourceType);
            \$('#h_target_id').val(targetId);
            \$('#h_target_type').val(targetType);
            \$('#h_mode').val(mode);
        }

        function countSelected() { return Object.keys(selectedIds).length; }

        function updateSelectedLabel() {
            \$('#selected-count-label').html('<b>' + countSelected() + '</b> dish(es) selected');
        }

        function clearDishes() {
            allDishes = [];
            selectedIds = {};
            \$('#step3, #step4').hide();
            \$('#target-stats, #source-stat-wrap').hide();
            \$('#dish-tbody').empty();
            \$('#dish-selection-wrap').hide();
            \$('#loading-dishes').hide();
            \$('#dish-search').val('');
            \$('#dish-category-filter').val('');
            \$('#dish-status-filter').val('all');
        }

        function applyModeUI() {
            if (mode === 'copy') {
                \$('#mode-copy-btn').addClass('active btn-success').removeClass('btn-default');
                \$('#mode-delete-btn').addClass('btn-default').removeClass('active btn-danger');
                \$('#step-source').show();
                \$('#step2-title').text('Target (Copy To)');
                \$('#step3-title').text('Select Dishes to Copy');
                \$('#status-col-th').text('Status at Target');
                \$('#status-filter-wrap').show();
                \$('#btn-select-missing-lbl').text('Select All Missing');
                \$('#btn-select-missing').show();
                \$('#stat-already-lbl').text('Already Exists');
                \$('#stat-missing-lbl').text('Missing / New');
                \$('#submit-btn').removeClass('btn-danger').addClass('btn-success');
                \$('#submit-btn-label').html('<i class="fa fa-copy"></i> Copy Selected Dishes');
                \$('#copy-form').attr('action', urlExecute);
            } else {
                \$('#mode-delete-btn').addClass('active btn-danger').removeClass('btn-default');
                \$('#mode-copy-btn').addClass('btn-default').removeClass('active btn-success');
                \$('#step-source').hide();
                \$('#step2-title').text('Target (Delete From)');
                \$('#step3-title').text('Select Dishes to Delete');
                \$('#status-col-th').text('Status');
                \$('#status-filter-wrap').hide();
                \$('#btn-select-missing').hide();
                \$('#stat-already-lbl').text('Total at Target');
                \$('#stat-missing-lbl').text('Currently Selected');
                \$('#submit-btn').removeClass('btn-success').addClass('btn-danger');
                \$('#submit-btn-label').html('<i class="fa fa-trash"></i> Delete Selected Dishes');
                \$('#copy-form').attr('action', urlDelete);
            }
            updateHiddens();
        }

        function getFiltered() {
            var q     = (\$('#dish-search').val() || '').toLowerCase().trim();
            var catId = \$('#dish-category-filter').val();
            var status= \$('#dish-status-filter').val();
            return allDishes.filter(function (d) {
                if (q && d.dish_name.toLowerCase().indexOf(q) === -1) return false;
                if (catId && String(d.category_id) !== String(catId))  return false;
                if (mode === 'copy') {
                    if (status === 'missing' && d.exists)  return false;
                    if (status === 'exists'  && !d.exists) return false;
                }
                return true;
            });
        }

        function renderDishes() {
            var dishes = getFiltered();
            var tbody = \$('#dish-tbody').empty();
            \$('#filter-count-label').html(dishes.length === allDishes.length
                ? ''
                : '(' + dishes.length + ' of ' + allDishes.length + ' shown)');

            if (!dishes.length) {
                tbody.append('<tr><td colspan="4" class="text-center text-muted">No dishes match the current filter.</td></tr>');
                return;
            }
            dishes.forEach(function (d) {
                var checked = selectedIds[d.dish_id] ? ' checked' : '';
                var nameCell, statusCell;
                if (mode === 'copy') {
                    var badge = d.exists
                        ? '<span class="exists-badge">&#10004; exists</span>'
                        : '<span class="missing-badge">&#9888; missing</span>';
                    nameCell = \$('<td>').append(\$('<span>').text(d.dish_name)).append(badge);
                    statusCell = \$('<td>').html(d.exists
                        ? '<span class="text-success"><i class="fa fa-check"></i> At target</span>'
                        : '<span class="text-warning"><i class="fa fa-clock-o"></i> Not copied</span>');
                } else {
                    nameCell = \$('<td>').append(\$('<span>').text(d.dish_name));
                    statusCell = \$('<td>').html('<span class="text-danger"><i class="fa fa-trash"></i> At target</span>');
                }
                var chk = '<input type="checkbox" class="dish-chk" data-id="' + d.dish_id + '"' + checked + '>';
                var row = \$('<tr>')
                    .toggleClass('text-muted', mode === 'copy' && !!d.exists)
                    .append(\$('<td>').html(chk))
                    .append(nameCell)
                    .append(\$('<td>').append(\$('<span class="cat-pill">').text(d.category_name || '—')))
                    .append(statusCell);
                tbody.append(row);
            });
        }

        function populateCategoryFilter() {
            var sel = \$('#dish-category-filter').empty()
                .append('<option value="">— All categories —</option>');
            var seen = {};
            allDishes.forEach(function (d) {
                if (d.category_id && !seen[d.category_id]) {
                    seen[d.category_id] = true;
                    sel.append(\$('<option>').val(d.category_id).text(d.category_name || ('Category #' + d.category_id)));
                }
            });
        }

        function loadDishes() {
            if (!targetId) return;
            if (mode === 'copy' && !sourceMid) return;

            \$('#step3').show();
            \$('#loading-dishes').show();
            \$('#dish-selection-wrap').hide();

            var url, params;
            if (mode === 'copy') {
                url = urlGetDishes;
                params = { source_merchant_id: sourceMid, source_type: sourceType, target_id: targetId, target_type: targetType };
            } else {
                url = urlGetTargetDishes;
                params = { target_id: targetId, target_type: targetType };
            }

            \$.getJSON(url, params)
            .done(function (data) {
                allDishes = data || [];
                selectedIds = {};
                // Nothing is pre-selected — the admin must explicitly pick the
                // dishes to copy/delete. (Auto-checking every missing dish meant
                // a category-filtered "select 47" still submitted everything that
                // had been pre-checked across other categories.) Use the
                // "Select Missing" button to bulk-select all missing on purpose.

                populateCategoryFilter();
                renderDishes();

                if (mode === 'copy') {
                    var missing = allDishes.filter(function(d){ return !d.exists; }).length;
                    var exists  = allDishes.length - missing;
                    \$('#stat-source-total').text(allDishes.length);
                    \$('#stat-already').text(exists);
                    \$('#stat-missing').text(missing);
                    \$('#source-stat-wrap').show();
                } else {
                    \$('#stat-already').text(allDishes.length);
                    \$('#stat-missing').text(0);
                    \$('#source-stat-wrap').hide();
                }
                \$('#target-stats').show();
                \$('#loading-dishes').hide();
                \$('#dish-selection-wrap').show();
                \$('#step4').show();
                updateSelectedLabel();
                updateHiddens();
            })
            .fail(function (xhr) {
                \$('#loading-dishes').hide();
                \$('#dish-tbody').html('<tr><td colspan="4" class="text-center text-danger"><i class="fa fa-warning"></i> Error loading dishes (HTTP ' + xhr.status + ')</td></tr>');
                \$('#dish-selection-wrap').show();
                console.error('load-dishes failed', xhr.status, xhr.responseText);
            });
        }

        /* ── Mode toggle ──────────────────────────────────────────── */
        \$('#mode-copy-btn').on('click', function () {
            if (mode === 'copy') return;
            mode = 'copy';
            clearDishes();
            applyModeUI();
            if (sourceMid && targetId) loadDishes();
        });
        \$('#mode-delete-btn').on('click', function () {
            if (mode === 'delete') return;
            mode = 'delete';
            clearDishes();
            applyModeUI();
            if (targetId) loadDishes();
        });

        /* ── source / target handlers ─────────────────────────────── */
        \$('#source_type_ui').on('change', function () {
            sourceType = \$(this).val();
            sourceMid  = 0;
            \$('#step2').hide();
            clearDishes();
            updateHiddens();
            if (sourceType === 'merchant') {
                \$('#source-merchant-wrap').show();
                \$('#source-outlet-wrap').hide();
                \$('#source_outlet_ui').val(null).trigger('change.select2');
                // Re-read the merchant select (it may still hold the default)
                sourceMid = parseInt(\$('#source_merchant_ui').val()) || 0;
                if (sourceMid) \$('#step2').show();
                updateHiddens();
                if (sourceMid && targetId) loadDishes();
            } else {
                \$('#source-merchant-wrap').hide();
                \$('#source-outlet-wrap').show();
                \$('#source_merchant_ui').val(null).trigger('change.select2');
            }
        });

        \$('#source_merchant_ui').on('select2:select select2:clear change', function () {
            if (sourceType !== 'merchant') return; // ignore programmatic clears while in outlet mode
            sourceMid = parseInt(\$('#source_merchant_ui').val()) || 0;
            if (sourceMid) \$('#step2').show(); else \$('#step2').hide();
            clearDishes();
            updateHiddens();
            if (sourceMid && targetId && mode === 'copy') loadDishes();
        });

        // Outlet source: same single searchable list of ALL outlets as the
        // target picker. Dishes at an outlet live under merchant_details_
        // merchant_id = outlet_id, so the outlet_id simply becomes the source id.
        \$('#source_outlet_ui').on('select2:select select2:clear change', function () {
            if (sourceType !== 'outlet') return; // ignore programmatic clears while in merchant mode
            sourceMid = parseInt(\$('#source_outlet_ui').val()) || 0;
            if (sourceMid) \$('#step2').show(); else \$('#step2').hide();
            clearDishes();
            updateHiddens();
            if (sourceMid && targetId && mode === 'copy') loadDishes();
        });

        \$('#target_type_ui').on('change', function () {
            targetType = \$(this).val();
            targetId   = 0;
            clearDishes();
            updateHiddens();
            if (targetType === 'merchant') {
                \$('#target-merchant-wrap').show();
                \$('#target-outlet-wrap').hide();
                \$('#target_outlet_ui').val(null).trigger('change.select2');
            } else {
                \$('#target-merchant-wrap').hide();
                \$('#target-outlet-wrap').show();
                \$('#target_merchant_ui').val(null).trigger('change.select2');
            }
        });

        \$('#target_merchant_ui').on('select2:select select2:clear change', function () {
            targetId   = parseInt(\$('#target_merchant_ui').val()) || 0;
            targetType = 'merchant';
            clearDishes();
            updateHiddens();
            if (targetId) loadDishes();
        });

        // Outlet target: a single searchable list of ALL outlets (each labelled
        // with its parent merchant). No parent-merchant pre-pick, so no outlet
        // can be hidden behind the wrong parent.
        \$('#target_outlet_ui').on('select2:select select2:clear change', function () {
            targetId   = parseInt(\$('#target_outlet_ui').val()) || 0;
            targetType = 'outlet';
            clearDishes();
            updateHiddens();
            if (targetId) loadDishes();
        });

        /* ── filters ──────────────────────────────────────────────── */
        var searchTimer;
        \$('#dish-search').on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(renderDishes, 120);
        });
        \$('#dish-category-filter, #dish-status-filter').on('change', renderDishes);

        /* ── checkbox handlers ────────────────────────────────────── */
        \$(document).on('change', '.dish-chk', function () {
            var id = \$(this).data('id');
            if (this.checked) selectedIds[id] = true; else delete selectedIds[id];
            updateSelectedLabel();
        });

        \$('#chk-all-top').on('change', function () {
            var on = this.checked;
            getFiltered().forEach(function (d) {
                if (on) selectedIds[d.dish_id] = true; else delete selectedIds[d.dish_id];
            });
            renderDishes();
            updateSelectedLabel();
        });

        \$('#btn-select-missing').on('click', function () {
            if (mode !== 'copy') return;
            allDishes.forEach(function (d) { if (!d.exists) selectedIds[d.dish_id] = true; });
            renderDishes();
            updateSelectedLabel();
        });

        \$('#btn-select-all').on('click', function () {
            getFiltered().forEach(function (d) { selectedIds[d.dish_id] = true; });
            renderDishes();
            updateSelectedLabel();
        });

        \$('#btn-clear').on('click', function () {
            selectedIds = {};
            renderDishes();
            updateSelectedLabel();
        });

        /* ── submit ───────────────────────────────────────────────── */
        \$('#copy-form').on('submit', function (e) {
            if (mode === 'copy' && !sourceMid) { alert('Please select a source merchant or outlet.'); e.preventDefault(); return; }
            if (!targetId)  { alert('Please select a target merchant or outlet.'); e.preventDefault(); return; }
            if (countSelected() === 0) {
                alert('Please select at least one dish to ' + (mode === 'copy' ? 'copy' : 'delete') + '.');
                e.preventDefault(); return;
            }
            if (mode === 'copy' && sourceMid === targetId && targetType === sourceType) {
                alert('Source and target are the same ' + sourceType + '. Please choose a different target.');
                e.preventDefault(); return;
            }
            var confirmMsg = mode === 'copy'
                ? 'Copy ' + countSelected() + ' dish(es)? Each will get a new Dish ID.'
                : 'Delete ' + countSelected() + ' dish(es) from the target? This cannot be undone.';
            if (!confirm(confirmMsg)) { e.preventDefault(); return; }

            // Inject hidden inputs for every selected ID
            var inputName = mode === 'copy' ? 'ds_dish[]' : 'del_dish[]';
            \$('#copy-form input[name="ds_dish[]"], #copy-form input[name="del_dish[]"]').remove();
            var form = \$('#copy-form');
            Object.keys(selectedIds).forEach(function (id) {
                \$('<input type="hidden">').attr('name', inputName).val(id).appendTo(form);
            });
        });

        applyModeUI();
    }

    function waitForJQuery() {
        if (typeof window.jQuery !== 'undefined') {
            window.jQuery(function () { boot(window.jQuery); });
        } else {
            setTimeout(waitForJQuery, 50);
        }
    }
    waitForJQuery();
})();
JS;

$this->registerJs($js, \yii\web\View::POS_END);
?>