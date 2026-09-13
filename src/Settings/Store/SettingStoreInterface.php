<?php

declare(strict_types=1);

namespace App\Settings\Store;

use App\Settings\SettingKey;

/**
 * Raw key/value access to the persisted settings.
 */
interface SettingStoreInterface
{
    /**
     * @return array<string, mixed> every stored value, indexed by key
     */
    public function all(): array;

    public function write(SettingKey $key, mixed $value): void;

    public function remove(SettingKey $key): void;
}
