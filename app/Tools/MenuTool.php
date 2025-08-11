<?php

namespace App\Tools;

use Illuminate\Support\Facades\DB;

class MenuTool
{
    public function getName(): string
    {
        return 'get_menu_data';
    }

    public function getDescription(): string
    {
        return 'Get menu data from the restaurant database based on various criteria';
    }

    public function getParameters(): array
    {
        return [
            'category' => [
                'type' => 'string',
                'description' => 'Filter by menu category (e.g., "makanan", "minuman", "dessert")',
                'nullable' => true,
            ],
            'price_range' => [
                'type' => 'object',
                'description' => 'Filter by price range',
                'properties' => [
                    'min' => ['type' => 'number', 'description' => 'Minimum price'],
                    'max' => ['type' => 'number', 'description' => 'Maximum price'],
                ],
                'nullable' => true,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Limit number of results (default: 10, max: 50)',
                'nullable' => true,
            ],
            'sort_by' => [
                'type' => 'string',
                'description' => 'Sort results by: "price", "name", "order_count", "created_at"',
                'nullable' => true,
            ],
            'sort_order' => [
                'type' => 'string',
                'description' => 'Sort order: "asc" or "desc" (default: "asc")',
                'nullable' => true,
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search term for menu name or description',
                'nullable' => true,
            ],
        ];
    }

    public function handle(array $arguments): string
    {
        try {
            $query = DB::table('menus');

            // Filter by category
            if (isset($arguments['category']) && ! empty($arguments['category'])) {
                $query->where('category', 'like', '%'.$arguments['category'].'%');
            }

            // Filter by price range
            if (isset($arguments['price_range']) && is_array($arguments['price_range'])) {
                if (isset($arguments['price_range']['min'])) {
                    $query->where('price', '>=', $arguments['price_range']['min']);
                }
                if (isset($arguments['price_range']['max'])) {
                    $query->where('price', '<=', $arguments['price_range']['max']);
                }
            }

            // Search in name and description
            if (isset($arguments['search']) && ! empty($arguments['search'])) {
                $searchTerm = '%'.$arguments['search'].'%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', $searchTerm)
                        ->orWhere('description', 'like', $searchTerm);
                });
            }

            // Sort results
            $sortBy = $arguments['sort_by'] ?? 'name';
            $sortOrder = $arguments['sort_order'] ?? 'asc';

            $allowedSortFields = ['name', 'price', 'order_count', 'created_at'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, strtolower($sortOrder) === 'desc' ? 'desc' : 'asc');
            }

            // Limit results
            $limit = min($arguments['limit'] ?? 10, 50);
            $results = $query->limit($limit)->get();

            if ($results->isEmpty()) {
                return json_encode([
                    'status' => 'not_found',
                    'message' => 'Tidak ada menu yang sesuai dengan kriteria',
                    'data' => [],
                ]);
            }

            return json_encode([
                'status' => 'success',
                'count' => $results->count(),
                'data' => $results->toArray(),
            ]);

        } catch (\Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat mengambil data menu: '.$e->getMessage(),
                'data' => [],
            ]);
        }
    }
}
