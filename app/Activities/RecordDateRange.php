<?php

namespace App\Activities;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class RecordDateRange
{
    private function __construct(public readonly array $filters) {}

    public static function fromRequest(Request $request): self
    {
        $rules = ['sometimes', 'bail', 'required', 'string', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d'];
        $filters = $request->validate(['date_from' => $rules, 'date_to' => $rules]);
        if (isset($filters['date_from'], $filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            throw ValidationException::withMessages(['date_to' => '结束日期必须在开始日期当天或之后。']);
        }

        return new self($filters);
    }

    public function from(): ?CarbonImmutable
    {
        $date = $this->date('date_from');

        return $date?->utc();
    }

    public function until(): ?CarbonImmutable
    {
        $date = $this->date('date_to');

        return $date?->addDay()->startOfDay()->utc();
    }

    private function date(string $key): ?CarbonImmutable
    {
        return isset($this->filters[$key])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->filters[$key], 'America/Sao_Paulo')
            : null;
    }

    /**
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function apply(Builder|QueryBuilder $query, string|Expression $column = 'created_at'): Builder|QueryBuilder
    {
        // Eloquent timestamps are written in the configured application timezone (UTC).
        if ($from = $this->from()) {
            $query->where($column, '>=', $from);
        }
        if ($until = $this->until()) {
            $query->where($column, '<', $until);
        }

        return $query;
    }
}
