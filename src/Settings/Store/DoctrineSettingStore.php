<?php

declare(strict_types=1);

namespace App\Settings\Store;

use App\Settings\Entity\Setting;
use App\Settings\Repository\SettingRepository;
use App\Settings\SettingKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(SettingStoreInterface::class)]
final class DoctrineSettingStore implements SettingStoreInterface
{
    public function __construct(
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function all(): array
    {
        $values = [];
        foreach ($this->repository->findAll() as $setting) {
            $values[$setting->getName()] = $setting->getValue();
        }

        return $values;
    }

    public function write(SettingKey $key, mixed $value): void
    {
        $setting = $this->repository->find($key->value);
        if (null === $setting) {
            $this->entityManager->persist(new Setting($key->value, $value));
        } else {
            $setting->changeValue($value);
        }

        $this->entityManager->flush();
    }

    public function remove(SettingKey $key): void
    {
        $setting = $this->repository->find($key->value);
        if (null === $setting) {
            return;
        }

        $this->entityManager->remove($setting);
        $this->entityManager->flush();
    }
}
