<?php

declare(strict_types=1);

namespace App\Platform\Import;

use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

/** The registered import templates (`config('eora.import_templates')`, code => class), current version each. */
final class ImportTemplates
{
    public function __construct(private readonly Container $container) {}

    /** @return list<ImportTemplate> */
    public function all(): array
    {
        $classes = (array) config('eora.import_templates', []);

        return array_values(array_map(fn (string $class) => $this->make($class), $classes));
    }

    public function get(string $code): ImportTemplate
    {
        $class = ((array) config('eora.import_templates', []))[$code] ?? null;

        return is_string($class) ? $this->make($class) : throw ValidationException::withMessages(['template' => 'Unknown import template.']);
    }

    private function make(string $class): ImportTemplate
    {
        $template = $this->container->make($class);

        return $template instanceof ImportTemplate ? $template : throw new \LogicException("{$class} is not an import template.");
    }
}
