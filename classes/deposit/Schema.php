<?php

namespace APP\plugins\generic\pln\classes\deposit;

use Illuminate\Support\Enumerable;
use PKP\plugins\Hook;

class Schema extends \PKP\maps\Schema
{
    public const SCHEMA_NAME = 'preservationNetworkDeposit';
    public string $schema = self::SCHEMA_NAME;

    /**
     * Registers schema
     */
    public static function register(): void
    {
        $path = dirname(__DIR__, 2) . '/schemas/deposit.json';
        Hook::add(
            'Schema::get::' . static::SCHEMA_NAME,
            fn (string $hookName, array $args) => $args[0] = json_decode(file_get_contents($path))
        );
    }

    /**
     * Map a deposit
     *
     * Includes all properties in the deposit schema.
     */
    public function map(Deposit $item): array
    {
        return $this->mapByProperties($this->getProps(), $item);
    }

    /**
     * Summarize a deposit
     *
     * Includes properties with the apiSummary flag in the deposit schema.
     */
    public function summarize(Deposit $item): array
    {
        return $this->mapByProperties($this->getSummaryProps(), $item);
    }

    /**
     * Map a collection of Deposits
     *
     * @see self::map
     */
    public function mapMany(Enumerable $collection): Enumerable
    {
        return $collection->map(fn (Deposit $item) => $this->map($item));
    }

    /**
     * Summarize a collection of Deposits
     *
     * @see self::summarize
     */
    public function summarizeMany(Enumerable $collection): Enumerable
    {
        return $collection->map(fn (Deposit $item) => $this->summarize($item));
    }

    /**
     * Map schema properties of a Deposit to an assoc array
     */
    protected function mapByProperties(array $props, Deposit $item): array
    {
        $values = collect($props)
            ->map(fn (string $prop) => $item->getData($prop))
            ->toArray();
        $output = $this->schemaService->addMissingMultilingualValues($this->schema, $values, $this->context->getSupportedSubmissionLocales());
        ksort($output);
        return $this->withExtensions($output, $item);
    }
}
