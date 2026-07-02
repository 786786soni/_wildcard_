<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use yii\web\ForbiddenHttpException;
use yii\helpers\ArrayHelper;
use app\models\MerchantDetails;
use app\models\OutletDetails;
use app\models\DishDetails;
use app\models\DishDetailsHasMenu;

/**
 * AdminCopyDishesController
 * Admin-only: copy dishes from any merchant OR outlet to any merchant or outlet
 * at any time. Dishes at an outlet live under merchant_details_merchant_id =
 * outlet_id (same convention the target side has always used), so an outlet
 * source works through the exact same queries — the outlet_id is simply passed
 * as source_merchant_id with source_type = 'outlet'.
 * Every dish copy always gets a new dish_id (isNewRecord = true, dish_id = null).
 */
class AdminCopyDishesController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Access: admin (auth_id = 1) only                                   */
    /* ------------------------------------------------------------------ */
    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [[
                    'allow'         => true,
                    'roles'         => ['@'],
                    'matchCallback' => function ($rule, $action) {
                        return (int) Yii::$app->user->identity->auth_id === 1;
                    },
                ]],
                'denyCallback' => function () {
                    throw new ForbiddenHttpException('Admin only.');
                },
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 1 – choose source merchant/outlet, target merchant/outlet,   */
    /*  dishes. $outletList feeds BOTH the source and target outlet picker. */
    /* ------------------------------------------------------------------ */
    public function actionIndex()
    {
        $flashes = Yii::$app->session->getAllFlashes();

        // Admin's own merchant_id (the master source)
        $adminMerchantId = \app\models\LoginDetails::find()
            ->where(['auth_id' => 1])
            ->one()->merchant_id ?? null;

        // All merchants (source list)
        $merchants = MerchantDetails::find()
            ->select(['merchant_id', 'restaurant_name'])
            ->orderBy('restaurant_name')
            ->asArray()->all();
        $merchantList = ArrayHelper::map($merchants, 'merchant_id', 'restaurant_name');

        // ALL outlets in one list (id => "Outlet Name — Parent Merchant").
        // The outlet target used to require first picking the right parent
        // merchant and then filtered outlets by that merchant_id — so outlets
        // parented to a different merchant simply never appeared. Listing every
        // outlet directly (labelled with its parent) guarantees completeness.
        $outlets = OutletDetails::find()
            ->select(['outlet_id', 'outlet_name', 'merchant_id', 'address', 'city'])
            ->orderBy('outlet_name')
            ->asArray()->all();
        $outletList = [];
        foreach ($outlets as $o) {
            $parent = $merchantList[$o['merchant_id']] ?? null;
            // Address in the label: same-named outlets (e.g. two "Gopal Sweets")
            // are otherwise indistinguishable — and Select2 search matches it too.
            $addr = trim((string) ($o['address'] ?? ''));
            if ($addr === '') { $addr = trim((string) ($o['city'] ?? '')); }
            if (mb_strlen($addr) > 45) { $addr = mb_substr($addr, 0, 42) . '…'; }
            $label  = ($o['outlet_name'] ?: ('Outlet #' . $o['outlet_id']))
                    . ($parent ? ' — ' . $parent : '')
                    . ($addr !== '' ? ' · ' . $addr : '');
            $outletList[$o['outlet_id']] = $label;
        }

        // Undo banner: details of the most recent copy in this session, if any
        $copyUndo = Yii::$app->session->get('copy_undo');

        return $this->render('index', [
            'merchantList'    => $merchantList,
            'outletList'      => $outletList,
            'adminMerchantId' => $adminMerchantId,
            'copyUndo'        => $copyUndo,
            'flashes'         => $flashes,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – outlets for a merchant                                       */
    /* ------------------------------------------------------------------ */
    public function actionGetOutlets()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $merchantId = (int) Yii::$app->request->get('merchant_id', 0);
        if (!$merchantId) return [];

        return OutletDetails::find()
            ->select(['outlet_id', 'outlet_name'])
            ->where(['merchant_id' => $merchantId])
            ->orderBy('outlet_name')
            ->asArray()->all();
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – dishes for a source merchant with exists flag for target    */
    /* ------------------------------------------------------------------ */
    public function actionGetDishes()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $sourceMerchantId = (int) Yii::$app->request->get('source_merchant_id', 0);
        $targetId         = (int) Yii::$app->request->get('target_id', 0);
        $targetType       = Yii::$app->request->get('target_type', 'merchant'); // 'merchant' | 'outlet'

        if (!$sourceMerchantId) return [];

        $dishes = DishDetails::find()
            ->select(['dish_id', 'dish_name', 'category_id', 'cuisine_id'])
            ->where(['merchant_details_merchant_id' => $sourceMerchantId])
            ->orderBy('dish_name')
            ->asArray()->all();

        // Resolve category names for the category filter UI
        $catIds = array_filter(array_unique(array_column($dishes, 'category_id')));
        $catMap = [];
        if (!empty($catIds)) {
            $catRows = \app\models\CategoryDetails::find()
                ->select(['category_id', 'category_name'])
                ->where(['category_id' => $catIds])
                ->asArray()->all();
            $catMap = \yii\helpers\ArrayHelper::map($catRows, 'category_id', 'category_name');
        }

        // Case-insensitive + whitespace-trimmed dish-name match at target
        $existingNamesNorm = [];
        if ($targetId) {
            $rows = DishDetails::find()
                ->select('dish_name')
                ->where(['merchant_details_merchant_id' => $targetId])
                ->column();
            foreach ($rows as $n) {
                $existingNamesNorm[] = strtolower(trim($n));
            }
        }

        foreach ($dishes as &$d) {
            $d['exists']        = in_array(strtolower(trim($d['dish_name'])), $existingNamesNorm, true);
            $d['category_name'] = $catMap[$d['category_id']] ?? '(Uncategorised)';
        }
        return $dishes;
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – dishes currently AT target (for delete-mode listing)         */
    /* ------------------------------------------------------------------ */
    public function actionGetTargetDishes()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $targetId = (int) Yii::$app->request->get('target_id', 0);
        if (!$targetId) return [];

        $dishes = DishDetails::find()
            ->select(['dish_id', 'dish_name', 'category_id', 'cuisine_id'])
            ->where(['merchant_details_merchant_id' => $targetId])
            ->orderBy('dish_name')
            ->asArray()->all();

        $catIds = array_filter(array_unique(array_column($dishes, 'category_id')));
        $catMap = [];
        if (!empty($catIds)) {
            $catRows = \app\models\CategoryDetails::find()
                ->select(['category_id', 'category_name'])
                ->where(['category_id' => $catIds])
                ->asArray()->all();
            $catMap = \yii\helpers\ArrayHelper::map($catRows, 'category_id', 'category_name');
        }

        foreach ($dishes as &$d) {
            $d['category_name'] = $catMap[$d['category_id']] ?? '(Uncategorised)';
        }
        return $dishes;
    }

    /* ------------------------------------------------------------------ */
    /*  Delete dishes at target (with pivot cleanup)                        */
    /* ------------------------------------------------------------------ */
    public function actionDeleteDishes()
    {
        if (!Yii::$app->request->isPost) {
            return $this->redirect(['index']);
        }

        $post       = Yii::$app->request->post();
        $targetId   = (int) ($post['target_id']   ?? 0);
        $targetType = $post['target_type']        ?? 'merchant';
        $dishIds    = $post['del_dish']           ?? [];

        if (!$targetId || empty($dishIds)) {
            Yii::$app->session->setFlash('error', [
                'title' => 'Validation Error',
                'text'  => 'Please select target and at least one dish to delete.',
                'type'  => 'error',
                'timer' => 4000,
                'showConfirmButton' => true,
            ]);
            return $this->redirect(['index']);
        }

        // Only allow deleting dishes that actually belong to the target (safety)
        $validIds = DishDetails::find()
            ->select('dish_id')
            ->where(['dish_id' => $dishIds, 'merchant_details_merchant_id' => $targetId])
            ->column();

        if (empty($validIds)) {
            Yii::$app->session->setFlash('error', [
                'title' => 'Error',
                'text'  => 'No valid dishes found at the selected target.',
                'type'  => 'error',
                'timer' => 4000,
                'showConfirmButton' => true,
            ]);
            return $this->redirect(['index']);
        }

        $deleted = 0;
        foreach ($validIds as $did) {
            // Full purge: dish row + every related table it owns (sizes, prices,
            // add-ons, tags, menu links, delivery types, availability, videos,
            // slots, size-config summary). Shared with Undo-last-copy.
            if ($this->purgeDish($did) > 0) {
                $deleted++;
            }
        }

        $targetLabel = $targetType === 'outlet'
            ? 'Outlet #' . $targetId . ' (' . (OutletDetails::findOne($targetId)->outlet_name ?? $targetId) . ')'
            : 'Merchant #' . $targetId . ' (' . (MerchantDetails::findOne($targetId)->restaurant_name ?? $targetId) . ')';

        Yii::$app->session->setFlash('success', [
            'title' => 'Delete Complete',
            'text'  => "{$deleted} dish(es) deleted from {$targetLabel}.",
            'type'  => 'success',
            'timer' => 6000,
            'showConfirmButton' => true,
        ]);

        return $this->redirect(['index']);
    }

    /* ------------------------------------------------------------------ */
    /*  Execute the copy                                                    */
    /* ------------------------------------------------------------------ */
    public function actionExecute()
    {
        if (!Yii::$app->request->isPost) {
            return $this->redirect(['index']);
        }

        $post             = Yii::$app->request->post();
        $sourceMerchantId = (int) ($post['source_merchant_id'] ?? 0);
        $sourceType       = $post['source_type'] ?? 'merchant'; // 'merchant' | 'outlet'
        $targetId         = (int) ($post['target_id']          ?? 0);
        $targetType       = $post['target_type'] ?? 'merchant';  // 'merchant' | 'outlet'
        $ds_dish          = $post['ds_dish'] ?? [];

        /* Validate -------------------------------------------------------- */
        if (!$sourceMerchantId || !$targetId || empty($ds_dish)) {
            Yii::$app->session->setFlash('error', [
                'title' => 'Validation Error',
                'text'  => 'Please select source, target and at least one dish.',
                'type'  => 'error',
                'timer' => 4000,
                'showConfirmButton' => true,
            ]);
            return $this->redirect(['index']);
        }

        // Same entity as both source and target would only produce "skipped"
        // rows at best and duplicated masters at worst — reject server-side
        // (the JS guard can be bypassed by a stale/hand-built form post).
        if ($sourceMerchantId === $targetId && $sourceType === $targetType) {
            Yii::$app->session->setFlash('error', [
                'title' => 'Validation Error',
                'text'  => 'Source and target are the same ' . $sourceType . '. Please choose a different target.',
                'type'  => 'error',
                'timer' => 4000,
                'showConfirmButton' => true,
            ]);
            return $this->redirect(['index']);
        }

        /*
         * The "merchant_details_merchant_id" column on dish/category/etc
         * rows uses:
         *   - merchant_id  when the target is a Merchant
         *   - outlet_id    when the target is an Outlet
         */
        $targetMerchantId = $targetId;

        /* ── 1. Fetch selected source dishes ─────────────────────────────── */
        $dishes = DishDetails::find()
            ->where(['dish_id' => $ds_dish, 'merchant_details_merchant_id' => $sourceMerchantId])
            ->all();

        if (empty($dishes)) {
            Yii::$app->session->setFlash('error', [
                'title' => 'Error',
                'text'  => 'No valid dishes found for the selected source ' . $sourceType . '.',
                'type'  => 'error',
                'timer' => 4000,
                'showConfirmButton' => true,
            ]);
            return $this->redirect(['index']);
        }

        /* ── 2. Collect all related IDs ──────────────────────────────────── */
        // Everything from here through the pivot re-link is one atomic unit:
        // a failure mid-way must not leave half-copied dishes at the target.
        $txn = Yii::$app->db->beginTransaction();
        try {

        $categoryIds = $cuisineIds = $tagCategoryIds = $tagIds = [];
        $unitIds = $sizeIds = $addonCategoryIds = $addonIds = [];

        foreach ($dishes as $dish) {
            if ($dish->category_id) $categoryIds[] = $dish->category_id;
            if ($dish->cuisine_id)  $cuisineIds[]  = $dish->cuisine_id;
            if ($dish->unit_id)     $unitIds[]     = $dish->unit_id;
            if ($dish->size_id)     $sizeIds[]     = $dish->size_id;

            foreach ($dish->tagCategoryDetailsHasDishDetailes as $m) {
                $tagCategoryIds[] = $m->tag_category_details_tag_category_id;
            }
            foreach ($dish->tagsDetailsTags as $t) {
                $tagIds[] = $t->tags_id;
            }
            foreach ($dish->addonDetails_cat as $ac) {
                $addonCategoryIds[] = $ac->addon_category_id;
            }
            foreach ($dish->addonDetailsAddons as $a) {
                $addonIds[] = $a->addon_id;
            }
        }

        // Menu placements of the selected dishes. Menus ARE merchant-scoped
        // (menu.merchant_details_merchant_id is set by MenuController even though
        // the model docblock omits it), so they are remapped by name like any
        // other master below.
        $menuIds = \app\models\DishDetailsHasMenu::find()
            ->select('menu_menu_id')
            ->where([
                'dish_details_dish_id'                      => $ds_dish,
                'dish_details_merchant_details_merchant_id' => $sourceMerchantId,
            ])
            ->distinct()->column();

        $categoryIds      = array_unique($categoryIds);
        $cuisineIds       = array_unique($cuisineIds);
        $tagCategoryIds   = array_unique($tagCategoryIds);
        $tagIds           = array_unique($tagIds);
        $unitIds          = array_unique($unitIds);
        $sizeIds          = array_unique($sizeIds);
        $addonCategoryIds = array_unique($addonCategoryIds);
        $addonIds         = array_unique($addonIds);
        $menuIds          = array_unique($menuIds);

        /* ── 3. Copy supporting records if missing at target ─────────────── */
        $idMap = []; // ['ModelClass:oldId' => newId]  for FK rewiring

        $copySupport = function ($modelClass, $ids, $pk, $nameField) use ($targetMerchantId, &$idMap) {
            if (empty($ids)) return;
            foreach ($ids as $id) {
                $old = $modelClass::findOne($id);
                if (!$old) continue;

                // Check by NAME at target (not by original ID — IDs may differ)
                $existing = $modelClass::find()
                    ->where([$nameField => $old->$nameField, 'merchant_details_merchant_id' => $targetMerchantId])
                    ->one();

                if ($existing) {
                    $idMap["\\{$modelClass}:{$id}"] = $existing->$pk;
                } else {
                    $new = new $modelClass();
                    $new->setAttributes($old->attributes, false);
                    $new->$pk        = null;
                    $new->merchant_details_merchant_id = $targetMerchantId;
                    $new->save(false);
                    $idMap["\\{$modelClass}:{$id}"] = $new->$pk;
                }
            }
        };

        $copySupport(\app\models\CategoryDetails::class,    $categoryIds,      'category_id',      'category_name');
        $copySupport(\app\models\CuisineDetails::class,     $cuisineIds,       'cuisine_id',       'cuisine_name');
        $copySupport(\app\models\TagCategoryDetails::class, $tagCategoryIds,   'tag_category_id',  'tag_category_name');
        $copySupport(\app\models\UnitDetails::class,        $unitIds,          'unit_id',          'unit_name');
        $copySupport(\app\models\AddonCategory::class,      $addonCategoryIds, 'addon_category_id','addon_category_name');
        $copySupport(\app\models\Menu::class,               $menuIds,          'menu_id',          'menu_name');

        // Tags depend on TagCategory — remap tag_category_id first
        foreach ($tagIds as $tid) {
            $old = \app\models\TagsDetails::findOne($tid);
            if (!$old) continue;
            $newTagCatId = $idMap["\app\models\TagCategoryDetails:{$old->tag_category_id}"] ?? $old->tag_category_id;
            $existing = \app\models\TagsDetails::find()
                ->where(['tags_name' => $old->tags_name, 'merchant_details_merchant_id' => $targetMerchantId])
                ->one();
            if ($existing) {
                $idMap["\app\models\TagsDetails:{$tid}"] = $existing->tags_id;
            } else {
                $new = new \app\models\TagsDetails();
                $new->setAttributes($old->attributes, false);
                $new->tags_id    = null;
                $new->merchant_details_merchant_id = $targetMerchantId;
                $new->tag_category_id = $newTagCatId;
                $new->save(false);
                $idMap["\app\models\TagsDetails:{$tid}"] = $new->tags_id;
            }
        }

        // AddonDetails depend on AddonCategory
        foreach ($addonIds as $aid) {
            $old = \app\models\AddonDetails::findOne($aid);
            if (!$old) continue;
            $newAddonCatId = $idMap["\app\models\AddonCategory:{$old->addon_category_id}"] ?? $old->addon_category_id;
            $existing = \app\models\AddonDetails::find()
                ->where(['addon_name' => $old->addon_name, 'merchant_details_merchant_id' => $targetMerchantId])
                ->one();
            if ($existing) {
                $idMap["\app\models\AddonDetails:{$aid}"] = $existing->addon_id;
            } else {
                $new = new \app\models\AddonDetails();
                $new->setAttributes($old->attributes, false);
                $new->addon_id          = null;
                $new->merchant_details_merchant_id = $targetMerchantId;
                $new->addon_category_id = $newAddonCatId;
                $new->save(false);
                $idMap["\app\models\AddonDetails:{$aid}"] = $new->addon_id;
            }
        }

        // SizeDetails depend on UnitDetails
        $allLinkedSizeIds = array_unique(array_merge(
            $sizeIds,
            \app\models\DishDetailsHasSizeDetails::find()
                ->select('size_details_size_id')
                ->where(['dish_details_dish_id' => $ds_dish])
                ->distinct()->column()
        ));
        foreach ($allLinkedSizeIds as $sid) {
            $old = \app\models\SizeDetails::findOne($sid);
            if (!$old) continue;
            $newUnitId = $idMap["\app\models\UnitDetails:{$old->unit_id}"] ?? $old->unit_id;
            $existing = \app\models\SizeDetails::find()
                ->where(['size_name' => $old->size_name, 'merchant_details_merchant_id' => $targetMerchantId])
                ->one();
            if ($existing) {
                $idMap["\app\models\SizeDetails:{$sid}"] = $existing->size_id;
            } else {
                $new = new \app\models\SizeDetails();
                $new->setAttributes($old->attributes, false);
                $new->size_id    = null;
                $new->merchant_details_merchant_id = $targetMerchantId;
                $new->unit_id    = $newUnitId;
                $new->save(false);
                $idMap["\app\models\SizeDetails:{$sid}"] = $new->size_id;
            }
        }

        /* ── 4. Copy dishes (always new dish_id) ─────────────────────────── */
        $copied  = 0;
        $skipped = 0;
        $dishIdMap       = []; // source dish_id → target dish_id (incl. skipped/existing)
        $copiedDishIdMap = []; // source dish_id → NEW dish_id (brand-new copies only)

        // Build a normalized map: lower-trim(name) => existing_dish_id at target
        $existingNameMap = [];
        $rowsAtTarget = DishDetails::find()
            ->select(['dish_id', 'dish_name'])
            ->where(['merchant_details_merchant_id' => $targetMerchantId])
            ->asArray()->all();
        foreach ($rowsAtTarget as $r) {
            $existingNameMap[strtolower(trim($r['dish_name']))] = $r['dish_id'];
        }

        foreach ($dishes as $dish) {
            $normName = strtolower(trim($dish->dish_name));
            if (isset($existingNameMap[$normName])) {
                // A same-named dish already exists at the target. Record the
                // mapping so dish-suggestion pairings can still resolve to it,
                // but DO NOT relink any pivots/detail onto it — relinking is
                // keyed off $copiedDishIdMap so a "skip" leaves the existing
                // dish completely untouched (no injected add-ons/tags/sizes,
                // no overwritten prices).
                $dishIdMap[$dish->dish_id] = $existingNameMap[$normName];
                $skipped++;
                continue;
            }

            $new = new DishDetails();
            // setAttributes(..., false) copies EVERY column verbatim, including
            // ones that are not "safe" for massive assignment (e.g. the image
            // path columns dish_image / additional_image / additional_image_2 /
            // With_background / Descriptive_Image, and all price fields) — so
            // images and prices are carried over, not silently dropped.
            $new->setAttributes($dish->attributes, false);
            $new->isNewRecord  = true;
            $new->dish_id      = null;  // always new ID
            $new->merchant_details_merchant_id = $targetMerchantId;
            // Remap every merchant-scoped FK to the target's copy.
            $new->category_id = $idMap["\app\models\CategoryDetails:{$dish->category_id}"] ?? $dish->category_id;
            $new->cuisine_id  = $idMap["\app\models\CuisineDetails:{$dish->cuisine_id}"]   ?? $dish->cuisine_id;
            $new->unit_id     = $idMap["\app\models\UnitDetails:{$dish->unit_id}"]         ?? $dish->unit_id;
            // size_id is the dish-level default size pointer. Without this remap
            // it dangles into the SOURCE merchant's size_details and the size
            // dropdown renders blank at the target (mismatched against unit_id).
            $new->size_id     = $idMap["\app\models\SizeDetails:{$dish->size_id}"]         ?? $dish->size_id;
            $new->dish_created = date('Y-m-d H:i:s');
            $new->dish_updated = date('Y-m-d H:i:s');

            if ($new->save(false)) {
                $dishIdMap[$dish->dish_id]       = $new->dish_id;
                $copiedDishIdMap[$dish->dish_id] = $new->dish_id;
                $existingNameMap[$normName]      = $new->dish_id;
                $copied++;
            }
        }

        /* ── 5. Re-link pivots & detail tables for NEWLY-COPIED dishes only ─
         *  Iterate $copiedDishIdMap (brand-new dishes), NOT $dishIdMap. A dish
         *  that already existed at the target (skipped) is left untouched.     */
        $summaryPairs = []; // "newDishId-newSizeId" => [newDishId, newSizeId]

        foreach ($copiedDishIdMap as $sourceDishId => $newDishId) {

            // Tag Categories
            foreach (\app\models\TagCategoryDetailsHasDishDetails::find()->where(['dish_details_dish_id' => $sourceDishId])->all() as $lnk) {
                $newTcId = $idMap["\app\models\TagCategoryDetails:{$lnk->tag_category_details_tag_category_id}"] ?? null;
                if ($newTcId && !\app\models\TagCategoryDetailsHasDishDetails::find()->where(['tag_category_details_tag_category_id' => $newTcId, 'dish_details_dish_id' => $newDishId])->exists()) {
                    (new \app\models\TagCategoryDetailsHasDishDetails(['tag_category_details_tag_category_id' => $newTcId, 'dish_details_dish_id' => $newDishId]))->save(false);
                }
            }

            // Tags
            foreach (\app\models\DishDetailsHasTagsDetails::find()->where(['dish_details_dish_id' => $sourceDishId])->all() as $lnk) {
                $newTagId = $idMap["\app\models\TagsDetails:{$lnk->tags_details_tags_id}"] ?? null;
                if ($newTagId && !\app\models\DishDetailsHasTagsDetails::find()->where(['tags_details_tags_id' => $newTagId, 'dish_details_dish_id' => $newDishId])->exists()) {
                    (new \app\models\DishDetailsHasTagsDetails(['tags_details_tags_id' => $newTagId, 'dish_details_dish_id' => $newDishId]))->save(false);
                }
            }

            // Addons (carry the addoncategory_id column too, remapped)
            foreach (\app\models\AddonDetailsHasDishDetails::find()->where(['dish_details_dish_id' => $sourceDishId])->all() as $lnk) {
                $newAddonId = $idMap["\app\models\AddonDetails:{$lnk->addon_details_addon_id}"] ?? null;
                if ($newAddonId && !\app\models\AddonDetailsHasDishDetails::find()->where(['addon_details_addon_id' => $newAddonId, 'dish_details_dish_id' => $newDishId])->exists()) {
                    $row = new \app\models\AddonDetailsHasDishDetails([
                        'addon_details_addon_id' => $newAddonId,
                        'dish_details_dish_id'   => $newDishId,
                    ]);
                    // addoncategory_id may be absent on some fleet schemas —
                    // read it from the attributes array so a missing column does
                    // not throw and roll back the whole copy.
                    $lnkAttrs = $lnk->attributes;
                    if (!empty($lnkAttrs['addoncategory_id'])) {
                        $row->addoncategory_id = $idMap["\app\models\AddonCategory:{$lnkAttrs['addoncategory_id']}"] ?? $lnkAttrs['addoncategory_id'];
                    }
                    $row->save(false);
                }
            }

            // Addon Categories
            foreach (\app\models\AddonCategoryHasDishDetails::find()->where(['dish_details_dish_id' => $sourceDishId])->all() as $lnk) {
                $newAcId = $idMap["\app\models\AddonCategory:{$lnk->addon_category_addon_category_id}"] ?? null;
                if ($newAcId && !\app\models\AddonCategoryHasDishDetails::find()->where(['addon_category_addon_category_id' => $newAcId, 'dish_details_dish_id' => $newDishId])->exists()) {
                    (new \app\models\AddonCategoryHasDishDetails(['addon_category_addon_category_id' => $newAcId, 'dish_details_dish_id' => $newDishId]))->save(false);
                }
            }

            // Size mappings + per-size price config (insert-only: the dish is new).
            // Copies every column verbatim (ds_mrp, ds_dish_price, ds_dis_percentage,
            // ds_serves, cost_price, landing_price, from_date, to_date, valid_from,
            // status) with size_details_size_id remapped to the target's size.
            foreach (\app\models\DishDetailsHasSizeDetails::find()->where(['dish_details_dish_id' => $sourceDishId])->all() as $lnk) {
                $newSizeId = $idMap["\app\models\SizeDetails:{$lnk->size_details_size_id}"] ?? null;
                if (!$newSizeId) continue;
                if (\app\models\DishDetailsHasSizeDetails::find()->where(['dish_details_dish_id' => $newDishId, 'size_details_size_id' => $newSizeId])->exists()) {
                    continue;
                }
                $attrs = $lnk->attributes;
                unset($attrs['ddhsd_id']);
                $nl = new \app\models\DishDetailsHasSizeDetails();
                $nl->setAttributes($attrs, false);
                $nl->isNewRecord          = true;
                $nl->dish_details_dish_id = $newDishId;
                $nl->size_details_size_id = $newSizeId;
                $nl->save(false);
                $summaryPairs[$newDishId . '-' . $newSizeId] = [$newDishId, $newSizeId];
            }
        }

            $txn->commit();
        } catch (\Throwable $e) {
            $txn->rollBack();
            Yii::error('Copy dishes failed: ' . $e->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('error', [
                'title' => 'Copy Failed',
                'text'  => 'An error occurred while copying — no changes were saved. ' . $e->getMessage(),
                'type'  => 'error',
                'timer' => 8000,
                'showConfirmButton' => true,
            ]);
            return $this->redirect(['index']);
        }

        /* ── 5b. Best-effort extras (POST-COMMIT) ─────────────────────────────
         *  Run AFTER the core copy is committed so that a missing optional table
         *  or a deadlock on one of these inserts can never roll back or silently
         *  lose the dishes / prices / sizes / add-ons. Every insert is existence
         *  -guarded and idempotent, so a re-run safely fills any gaps.            */
        foreach ($copiedDishIdMap as $sourceDishId => $newDishId) {

            // Menu placements (menu master already remapped via $idMap[Menu:*]).
            try {
                foreach (\app\models\DishDetailsHasMenu::find()->where(['dish_details_dish_id' => $sourceDishId, 'dish_details_merchant_details_merchant_id' => $sourceMerchantId])->all() as $lnk) {
                    $newMenuId = $idMap["\app\models\Menu:{$lnk->menu_menu_id}"] ?? null;
                    if (!$newMenuId) continue;
                    $exists = \app\models\DishDetailsHasMenu::find()->where([
                        'dish_details_dish_id'                      => $newDishId,
                        'dish_details_merchant_details_merchant_id' => $targetMerchantId,
                        'menu_menu_id'                              => $newMenuId,
                    ])->exists();
                    if (!$exists) {
                        (new \app\models\DishDetailsHasMenu([
                            'dish_details_dish_id'                      => $newDishId,
                            'dish_details_merchant_details_merchant_id' => $targetMerchantId,
                            'menu_menu_id'                              => $newMenuId,
                        ]))->save(false);
                    }
                }
            } catch (\Throwable $e) { Yii::error('Copy menu link failed: ' . $e->getMessage(), __METHOD__); }

            // Delivery types (global lookup — id carried verbatim, no remap)
            try {
                foreach (\app\models\DishDetailsHasDishDeliveryType::find()->where(['dish_details_dish_id' => $sourceDishId])->all() as $lnk) {
                    $dt = $lnk->dish_delivery_type_dish_delivery_type_id;
                    if (!\app\models\DishDetailsHasDishDeliveryType::find()->where(['dish_details_dish_id' => $newDishId, 'dish_delivery_type_dish_delivery_type_id' => $dt])->exists()) {
                        (new \app\models\DishDetailsHasDishDeliveryType([
                            'dish_details_dish_id'                     => $newDishId,
                            'dish_delivery_type_dish_delivery_type_id' => $dt,
                        ]))->save(false);
                    }
                }
            } catch (\Throwable $e) { Yii::error('Copy delivery type failed: ' . $e->getMessage(), __METHOD__); }

            // Limited-time availability windows (keyed by dish_id only)
            try {
                foreach (\app\models\DishAvailableTime::find()->where(['dish_id' => $sourceDishId])->all() as $lnk) {
                    $attrs = $lnk->attributes;
                    unset($attrs['dish_available_time_id']);
                    $nl = new \app\models\DishAvailableTime();
                    $nl->setAttributes($attrs, false);
                    $nl->isNewRecord = true;
                    $nl->dish_id     = $newDishId;
                    $nl->save(false);
                }
            } catch (\Throwable $e) { Yii::error('Copy available-time failed: ' . $e->getMessage(), __METHOD__); }

            // Promo videos (thumbnail/video paths are merchant-agnostic — carry verbatim)
            try {
                foreach (\app\models\DishDetailsVideo::find()->where(['dish_id' => $sourceDishId])->all() as $lnk) {
                    $attrs = $lnk->attributes;
                    unset($attrs['id']);
                    $nl = new \app\models\DishDetailsVideo();
                    $nl->setAttributes($attrs, false);
                    $nl->isNewRecord = true;
                    $nl->dish_id     = $newDishId;
                    $nl->save(false);
                }
            } catch (\Throwable $e) { Yii::error('Copy video failed: ' . $e->getMessage(), __METHOD__); }

            // Slot restrictions (best-effort: column set varies across the fleet)
            try {
                foreach (\app\models\DishSlotMapping::find()->where(['dish_id' => $sourceDishId])->all() as $lnk) {
                    if (\app\models\DishSlotMapping::find()->where(['dish_id' => $newDishId, 'slot_id' => $lnk->slot_id])->exists()) {
                        continue;
                    }
                    $nl = new \app\models\DishSlotMapping();
                    $nl->dish_id = $newDishId;
                    $nl->slot_id = $lnk->slot_id;
                    $nl->save(false);
                }
            } catch (\Throwable $e) { Yii::error('Copy slot mapping failed: ' . $e->getMessage(), __METHOD__); }
        }

        // Regenerate the per-size configuration summary cache (the "No
        // configuration summary found" panel reads this). updateSummary() recounts
        // from the committed size rows.
        foreach ($summaryPairs as $pair) {
            try {
                \app\models\DishDetailsHasSizeDetails::updateSummary($pair[0], $pair[1]);
            } catch (\Throwable $e) { Yii::error('updateSummary failed: ' . $e->getMessage(), __METHOD__); }
        }

        // Dish suggestions: re-create a pairing only when BOTH ends are part of
        // this copy batch. Uses $dishIdMap (incl. skipped) so a suggestion that
        // points at an already-existing target dish still resolves to it.
        try {
            foreach ($copiedDishIdMap as $sourceDishId => $newDishId) {
                foreach (\app\models\DishSuggested::find()->where(['dish_primary_id' => $sourceDishId])->all() as $sugg) {
                    $newSecId = $dishIdMap[$sugg->dish_secondry_id] ?? null;
                    if ($newSecId && !\app\models\DishSuggested::find()->where(['dish_primary_id' => $newDishId, 'dish_secondry_id' => $newSecId])->exists()) {
                        (new \app\models\DishSuggested(['dish_primary_id' => $newDishId, 'dish_secondry_id' => $newSecId]))->save(false);
                    }
                }
            }
        } catch (\Throwable $e) { Yii::error('Copy suggestions failed: ' . $e->getMessage(), __METHOD__); }

        /* ── 6. Flash & redirect ─────────────────────────────────────────── */
        $targetLabel = $targetType === 'outlet'
            ? 'Outlet #' . $targetId . ' (' . (OutletDetails::findOne($targetId)->outlet_name ?? $targetId) . ')'
            : 'Merchant #' . $targetId . ' (' . (MerchantDetails::findOne($targetId)->restaurant_name ?? $targetId) . ')';

        $sourceLabel = $sourceType === 'outlet'
            ? 'Outlet #' . $sourceMerchantId . ' (' . (OutletDetails::findOne($sourceMerchantId)->outlet_name ?? $sourceMerchantId) . ')'
            : 'Merchant #' . $sourceMerchantId . ' (' . (MerchantDetails::findOne($sourceMerchantId)->restaurant_name ?? $sourceMerchantId) . ')';

        // Record this batch so the admin can one-click Undo a mistaken copy.
        // Only the brand-new dish_ids are tracked (skipped/pre-existing dishes
        // are never touched by Undo).
        if ($copied > 0) {
            Yii::$app->session->set('copy_undo', [
                'target_id'    => $targetId,
                'target_type'  => $targetType,
                'target_label' => $targetLabel,
                'dish_ids'     => array_values($copiedDishIdMap),
                'count'        => $copied,
                'when'         => date('Y-m-d H:i:s'),
            ]);
        } else {
            Yii::$app->session->remove('copy_undo');
        }

        Yii::$app->session->setFlash('success', [
            'title' => 'Copy Complete',
            'text'  => "{$copied} dish(es) copied from {$sourceLabel} to {$targetLabel}."
                     . ($skipped ? " {$skipped} already existed and were skipped." : '')
                     . ($copied ? ' Use "Undo last copy" if this was a mistake.' : ''),
            'type'  => 'success',
            'timer' => 6000,
            'showConfirmButton' => true,
        ]);

        return $this->redirect(['index']);
    }

    /* ------------------------------------------------------------------ */
    /*  Remove a dish and EVERY related row it owns (full purge).          */
    /*  Shared by Delete-mode and Undo-last-copy so a removed dish leaves  */
    /*  no orphan sizes / add-ons / menu links / videos / summary rows.    */
    /* ------------------------------------------------------------------ */
    private function purgeDish($dishId)
    {
        \app\models\TagCategoryDetailsHasDishDetails::deleteAll(['dish_details_dish_id' => $dishId]);
        \app\models\DishDetailsHasTagsDetails::deleteAll(['dish_details_dish_id' => $dishId]);
        \app\models\AddonDetailsHasDishDetails::deleteAll(['dish_details_dish_id' => $dishId]);
        \app\models\AddonCategoryHasDishDetails::deleteAll(['dish_details_dish_id' => $dishId]);
        \app\models\DishDetailsHasSizeDetails::deleteAll(['dish_details_dish_id' => $dishId]);
        \app\models\DishSuggested::deleteAll(['or', ['dish_primary_id' => $dishId], ['dish_secondry_id' => $dishId]]);
        \app\models\DishDetailsHasMenu::deleteAll(['dish_details_dish_id' => $dishId]);

        // Best-effort extras (these tables vary across the fleet)
        try { \app\models\DishDetailsHasDishDeliveryType::deleteAll(['dish_details_dish_id' => $dishId]); } catch (\Throwable $e) {}
        try { \app\models\DishAvailableTime::deleteAll(['dish_id' => $dishId]); } catch (\Throwable $e) {}
        try { \app\models\DishDetailsVideo::deleteAll(['dish_id' => $dishId]); } catch (\Throwable $e) {}
        try { \app\models\DishSlotMapping::deleteAll(['dish_id' => $dishId]); } catch (\Throwable $e) {}
        try { Yii::$app->db->createCommand()->delete('size_configuration_summary', ['dish_id' => $dishId])->execute(); } catch (\Throwable $e) {}

        return DishDetails::deleteAll(['dish_id' => $dishId]);
    }

    /* ------------------------------------------------------------------ */
    /*  Undo the most recent copy recorded in this session                 */
    /* ------------------------------------------------------------------ */
    public function actionUndoLast()
    {
        if (!Yii::$app->request->isPost) {
            return $this->redirect(['index']);
        }

        $undo = Yii::$app->session->get('copy_undo');
        if (empty($undo) || empty($undo['dish_ids']) || empty($undo['target_id'])) {
            Yii::$app->session->setFlash('error', [
                'title' => 'Nothing to Undo',
                'text'  => 'There is no recent copy to undo in this session.',
                'type'  => 'error',
                'timer' => 4000,
                'showConfirmButton' => true,
            ]);
            return $this->redirect(['index']);
        }

        // Only delete dishes that STILL belong to the recorded target — never
        // touch anything the admin may have edited/renamed in the meantime.
        $validIds = DishDetails::find()
            ->select('dish_id')
            ->where(['dish_id' => $undo['dish_ids'], 'merchant_details_merchant_id' => $undo['target_id']])
            ->column();

        $deleted = 0;
        $txn = Yii::$app->db->beginTransaction();
        try {
            foreach ($validIds as $did) {
                if ($this->purgeDish($did) > 0) {
                    $deleted++;
                }
            }
            $txn->commit();
        } catch (\Throwable $e) {
            $txn->rollBack();
            Yii::error('Undo last copy failed: ' . $e->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('error', [
                'title' => 'Undo Failed',
                'text'  => 'Could not undo the last copy — no changes were made. ' . $e->getMessage(),
                'type'  => 'error',
                'timer' => 7000,
                'showConfirmButton' => true,
            ]);
            return $this->redirect(['index']);
        }

        Yii::$app->session->remove('copy_undo');

        Yii::$app->session->setFlash('success', [
            'title' => 'Undo Complete',
            'text'  => "{$deleted} dish(es) from the last copy were removed from {$undo['target_label']}.",
            'type'  => 'success',
            'timer' => 6000,
            'showConfirmButton' => true,
        ]);
        return $this->redirect(['index']);
    }
}