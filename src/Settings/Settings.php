<?php

declare(strict_types=1);

namespace App\Settings;

use App\Settings\Store\SettingStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Typed, cached read and write access to the application settings.
 *
 * Values are loaded once per request; in worker mode the kernel reset clears the
 * snapshot between requests.
 */
final class Settings implements ResetInterface
{
    /**
     * Used when neither the settings nor the environment name a model, so that a
     * misconfigured instance still produces a readable API error instead of a
     * type error.
     */
    private const string FALLBACK_TEXT_MODEL = 'gemini-3.1-flash-lite';
    private const string FALLBACK_IMAGE_MODEL = 'gemini-3.1-flash-image';

    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    public function __construct(
        private readonly SettingStoreInterface $store,
        #[Autowire(env: 'GEMINI_TEXT_MODEL')]
        private readonly string $defaultTextModel,
        #[Autowire(env: 'GEMINI_IMAGE_MODEL')]
        private readonly string $defaultImageModel,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey = '',
    ) {
    }

    /**
     * Without a key, every analysis and every generation would fail in a
     * background job, which is a bad place to discover a missing setting.
     */
    public function isGeminiConfigured(): bool
    {
        return '' !== trim($this->geminiApiKey);
    }

    public function getRaw(SettingKey $key): mixed
    {
        return $this->load()[$key->value] ?? $key->defaultValue();
    }

    public function getInt(SettingKey $key): int
    {
        $value = $this->getRaw($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function getFloat(SettingKey $key): float
    {
        $value = $this->getRaw($key);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    public function getString(SettingKey $key): string
    {
        $value = $this->getRaw($key);

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getArray(SettingKey $key): array
    {
        $value = $this->getRaw($key);

        return \is_array($value) ? $value : [];
    }

    public function getDateTime(SettingKey $key): ?\DateTimeImmutable
    {
        $value = $this->getRaw($key);
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return non-empty-string
     */
    public function getTextModel(): string
    {
        return $this->modelOrDefault(SettingKey::GeminiTextModel, $this->defaultTextModel, self::FALLBACK_TEXT_MODEL);
    }

    /**
     * @return non-empty-string
     */
    public function getImageModel(): string
    {
        return $this->modelOrDefault(SettingKey::GeminiImageModel, $this->defaultImageModel, self::FALLBACK_IMAGE_MODEL);
    }

    public function isOnboardingCompleted(): bool
    {
        return null !== $this->getDateTime(SettingKey::OnboardingCompletedAt);
    }

    public function set(SettingKey $key, mixed $value): void
    {
        $this->store->write($key, $value);
        $this->snapshot = null;
    }

    /**
     * @param array<string, mixed> $values indexed by {@see SettingKey} value
     *
     * @throws \InvalidArgumentException when a key is not a known setting
     */
    public function setMany(array $values): void
    {
        foreach ($values as $name => $value) {
            $key = SettingKey::tryFrom($name);
            if (null === $key) {
                throw new \InvalidArgumentException(\sprintf('Unknown setting "%s".', $name));
            }

            $this->store->write($key, $value);
        }

        $this->snapshot = null;
    }

    /**
     * Drops a stored value so the key falls back on its default.
     */
    public function clear(SettingKey $key): void
    {
        $this->store->remove($key);
        $this->snapshot = null;
    }

    /**
     * Forgets the cached snapshot between two requests of a long-running worker.
     */
    public function reset(): void
    {
        $this->snapshot = null;
    }

    /**
     * @param non-empty-string $lastResort
     *
     * @return non-empty-string
     */
    private function modelOrDefault(SettingKey $key, string $environmentValue, string $lastResort): string
    {
        foreach ([trim($this->getString($key)), trim($environmentValue)] as $candidate) {
            if ('' !== $candidate) {
                return $candidate;
            }
        }

        return $lastResort;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        return $this->snapshot ??= $this->store->all();
    }
}
