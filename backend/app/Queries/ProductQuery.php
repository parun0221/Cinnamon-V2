<?php

namespace App\Queries;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProductQuery
{
    public function __construct(
        private Builder $query,
    ) {}

    public static function build(): self
    {
        return new self(Product::query()->with('category'));
    }

    public function filter(array $params): self
    {
        if (! empty($params['category_id'])) {
            $this->query->where('category_id', $params['category_id']);
        }

        if (! empty($params['search'])) {
            $search = $params['search'];
            $this->query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if (isset($params['is_active'])) {
            $this->query->where('is_active', $params['is_active']);
        }

        if (! empty($params['stock_status'])) {
            match ($params['stock_status']) {
                'low' => $this->query->whereColumn('ready_stock', '<=', 'minimum_stock')
                    ->where('minimum_stock', '>', 0),
                'out' => $this->query->where('ready_stock', 0),
                default => null,
            };
        }

        return $this;
    }

    public function sort(?string $sort): self
    {
        $allowlist = [
            'name' => 'name',
            '-name' => 'name:desc',
            'sku' => 'sku',
            '-sku' => 'sku:desc',
            'selling_price' => 'selling_price',
            '-selling_price' => 'selling_price:desc',
            'ready_stock' => 'ready_stock',
            '-ready_stock' => 'ready_stock:desc',
            'created_at' => 'created_at',
            '-created_at' => 'created_at:desc',
        ];

        if ($sort && isset($allowlist[$sort])) {
            $parts = explode(':', $allowlist[$sort]);
            $column = $parts[0];
            $direction = $parts[1] ?? 'asc';
            $this->query->orderBy($column, $direction);
        } else {
            $this->query->orderBy('name');
        }

        return $this;
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }
}
