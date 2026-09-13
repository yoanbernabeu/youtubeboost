<?php

declare(strict_types=1);

namespace App\Settings\Entity;

use App\Settings\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One persisted setting. The value is stored as JSON so scalars, arrays and
 * nested weight maps share the same column.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $value;

    public function __construct(string $name, mixed $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function changeValue(mixed $value): void
    {
        $this->value = $value;
    }
}
