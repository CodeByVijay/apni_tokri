<?php

declare(strict_types=1);

final class FaqService
{
    /** @var list<string> */
    private const ADMIN_FAQ_SORT_COLUMNS = ['sort_order', 'id', 'question'];

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, id: int}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function createCategory(array $input): array
    {
        $validated = self::validateCategoryInput($input, null);
        if (!$validated['ok']) {
            return $validated;
        }
        try {
            $id = FaqRepository::insertCategory($validated['data']);

            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            error_log('FaqService::createCategory: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not create category.'];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, id: int}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function updateCategory(int $id, array $input): array
    {
        if ($id <= 0 || FaqRepository::findCategoryById($id) === null) {
            return ['ok' => false, 'message' => 'Category not found.'];
        }
        $validated = self::validateCategoryInput($input, $id);
        if (!$validated['ok']) {
            return $validated;
        }
        try {
            FaqRepository::updateCategory($id, $validated['data']);

            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            error_log('FaqService::updateCategory: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not update category.'];
        }
    }

    public static function setCategoryActive(int $id, bool $active): array
    {
        if ($id <= 0 || FaqRepository::findCategoryById($id) === null) {
            return ['ok' => false, 'message' => 'Category not found.'];
        }
        FaqRepository::setCategoryActive($id, $active);

        return ['ok' => true, 'id' => $id];
    }

    public static function deleteCategory(int $id): array
    {
        if ($id <= 0 || FaqRepository::findCategoryById($id) === null) {
            return ['ok' => false, 'message' => 'Category not found.'];
        }
        if (FaqRepository::faqCountForCategory($id) > 0) {
            return ['ok' => false, 'message' => 'Remove or reassign FAQs in this category before deleting it.'];
        }
        try {
            FaqRepository::deleteCategory($id);

            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            error_log('FaqService::deleteCategory: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not delete category.'];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, id: int}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function createFaq(array $input): array
    {
        $validated = self::validateFaqInput($input, null);
        if (!$validated['ok']) {
            return $validated;
        }
        try {
            $id = FaqRepository::insertFaq($validated['data']);

            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            error_log('FaqService::createFaq: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not create FAQ.'];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, id: int}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function updateFaq(int $id, array $input): array
    {
        if ($id <= 0 || FaqRepository::findFaqById($id) === null) {
            return ['ok' => false, 'message' => 'FAQ not found.'];
        }
        $validated = self::validateFaqInput($input, $id);
        if (!$validated['ok']) {
            return $validated;
        }
        try {
            FaqRepository::updateFaq($id, $validated['data']);

            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            error_log('FaqService::updateFaq: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not update FAQ.'];
        }
    }

    public static function setFaqActive(int $id, bool $active): array
    {
        $faq = FaqRepository::findFaqById($id);
        if ($faq === null) {
            return ['ok' => false, 'message' => 'FAQ not found.'];
        }
        if ($active) {
            $catErr = self::categoryAssociationError((int) ($faq['category_id'] ?? 0), true);
            if ($catErr !== null) {
                return ['ok' => false, 'message' => $catErr];
            }
        }
        FaqRepository::setFaqActive($id, $active);

        return ['ok' => true, 'id' => $id];
    }

    public static function deleteFaq(int $id): array
    {
        if ($id <= 0 || FaqRepository::findFaqById($id) === null) {
            return ['ok' => false, 'message' => 'FAQ not found.'];
        }
        try {
            FaqRepository::deleteFaq($id);

            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            error_log('FaqService::deleteFaq: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not delete FAQ.'];
        }
    }

    /** @return array{ok: true, data: array<string, mixed>}|array{ok: false, message: string, errors: array<string, string>} */
    private static function validateCategoryInput(array $input, ?int $excludeId): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191) {
            $errors['name'] = 'Name is required (max 191 characters).';
        }
        $slugRaw = trim((string) ($input['slug'] ?? ''));
        if ($slugRaw === '') {
            $slug = CatalogueSlug::fromName($name);
        } else {
            $slug = strtolower($slugRaw);
        }
        if (!Validation::slug($slug)) {
            $errors['slug'] = 'Invalid slug format.';
        } elseif (FaqRepository::categorySlugTaken($slug, $excludeId)) {
            $errors['slug'] = 'This slug is already in use.';
        }
        $sortOrder = (int) ($input['sort_order'] ?? 0);
        $isActive = !empty($input['is_active']) ? 1 : 0;
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Validation failed.', 'errors' => $errors];
        }

        return [
            'ok' => true,
            'data' => [
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ],
        ];
    }

    /** @return array{ok: true, data: array<string, mixed>}|array{ok: false, message: string, errors: array<string, string>} */
    private static function validateFaqInput(array $input, ?int $excludeId): array
    {
        $errors = [];
        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '' || mb_strlen($question) > 255) {
            $errors['question'] = 'Question is required (max 255 characters).';
        }
        $answer = trim((string) ($input['answer'] ?? ''));
        if ($answer === '') {
            $errors['answer'] = 'Answer is required.';
        }
        $categoryId = isset($input['category_id']) ? (int) $input['category_id'] : 0;
        if ($categoryId <= 0) {
            $errors['category_id'] = 'Category is required.';
        }
        $isActive = !empty($input['is_active']) ? 1 : 0;
        $catErr = self::categoryAssociationError($categoryId, (bool) $isActive);
        if ($catErr !== null) {
            $errors['category_id'] = $catErr;
        }
        $sortOrder = (int) ($input['sort_order'] ?? 0);
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Validation failed.', 'errors' => $errors];
        }

        return [
            'ok' => true,
            'data' => [
                'category_id' => $categoryId,
                'question' => $question,
                'answer' => $answer,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ],
        ];
    }

    private static function categoryAssociationError(int $categoryId, bool $faqActive): ?string
    {
        if ($categoryId <= 0) {
            return 'Choose a valid category.';
        }
        $cat = FaqRepository::findCategoryById($categoryId);
        if ($cat === null) {
            return 'Category does not exist.';
        }
        if ($faqActive && FaqRepository::categoryHasIsActiveColumn() && empty($cat['is_active'])) {
            return 'Cannot activate FAQ while its category is inactive.';
        }

        return null;
    }

    public static function normalizeAdminSort(string $sort, string $dir): array
    {
        $sort = in_array($sort, self::ADMIN_FAQ_SORT_COLUMNS, true) ? $sort : 'sort_order';
        $dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';

        return [$sort, $dir];
    }

    public static function escapePlainText(string $text): string
    {
        return Security::escape($text);
    }
}
