<?php

declare(strict_types=1);

namespace App\Settings\Store;

use App\Settings\SettingKey;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * In-memory store, used by unit tests and as a null object.
 */
#[Exclude]
final class InMemorySettingStore implements SettingStoreInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function all(): array
    {
        return $this->values;
    }

    public function write(SettingKey $key, mixed $value): void
    {
        $this->values[$key->value] = $value;
    }

    public function remove(SettingKey $key): void
    {
        unset($this->values[$key->value]);
    }
}
