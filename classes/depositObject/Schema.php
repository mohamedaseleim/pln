<?php

namespace APP\plugins\generic\pln\classes\depositObject;

use Illuminate\Support\Enumerable;
use PKP\plugins\Hook;

class Schema extends \PKP\maps\Schema
{
    public const SCHEMA_NAME = 'preservationNetworkDepositObject';
    public string $schema = self::SCHEMA_NAME;

    /**
     * Registers schema
     */
    public static function register(): void
    {
        $path = dirname(__DIR__, 2) . '/schemas/depositObject.json';
        Hook::add(
            'Schema::get::' . static::SCHEMA_NAME,
            fn (string $hookName, array $args) => $args[0] = json_decode(file_get_contents($path))
        );
    }

    /**
     * Map a deposit object
     *
     * Includes all properties in the schema.
     */
    public function map(DepositObject $item): array
    {
        return $this->mapByProperties($this->getProps(), $item);
    }

    /**
     * Summarize a deposit object
     *
     * Includes properties with the apiSummary flag in the schema.
     */
    public function summarize(DepositObject $item): array
    {
        return $this->mapByProperties($this->getSummaryProps(), $item);
    }

    /**
     * Map a collection of Deposit Objects
     *
     * @see self::map
     */
    public function mapMany(Enumerable $collection): Enumerable
    {
        return $collection->map(fn (DepositObject $item) => $this->map($item));
    }

    /**
     * Summarize a collection of Deposit Objects
     *
     * @see self::summarize
     */
    public function summarizeMany(Enumerable $collection): Enumerable
    {
        return $collection->map(fn (DepositObject $item) => $this->summarize($item));
    }

    /**
     * Map schema properties of a Deposit Object to an assoc array
     */
    protected function mapByProperties(array $props, DepositObject $item): array
    {
        $values = collect($props)
            ->map(fn (string $prop) => $item->getData($prop))
            ->toArray();
        $output = $this->schemaService->addMissingMultilingualValues($this->schema, $values, $this->context->getSupportedSubmissionLocales());
        ksort($output);
        return $this->withExtensions($output, $item);
    }
}
