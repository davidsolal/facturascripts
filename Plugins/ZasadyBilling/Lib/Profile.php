<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

use FacturaScripts\Core\Model\Empresa;
use FacturaScripts\Core\Tools;

/**
 * Per-company monthly billing profile, persisted through the core settings
 * table (one settings group per idempresa) rather than a dedicated table:
 * there's no need for a custom schema/migration for a handful of key-value
 * fields that are only ever read/written one company at a time.
 */
final class Profile
{
    public int $idempresa;
    public bool $enabled = false;
    public string $codserie = '';
    public string $codcliente = '';
    public float $amount = 0.0;
    public int $day = 19;
    public float $rateOverride = 0.0;
    public string $description = '';

    public static function group(int $idempresa): string
    {
        return 'zasady-' . $idempresa;
    }

    public static function fromArray(int $idempresa, array $raw): self
    {
        $p = new self();
        $p->idempresa = $idempresa;
        $p->enabled = ($raw['enabled'] ?? '0') === '1';
        $p->codserie = (string)($raw['codserie'] ?? '');
        $p->codcliente = (string)($raw['codcliente'] ?? '');
        $p->amount = (float)($raw['amount'] ?? 0);
        $p->day = (int)($raw['day'] ?? 19);
        $p->rateOverride = (float)($raw['rate_override'] ?? 0);
        $p->description = (string)($raw['description'] ?? '');
        return $p;
    }

    public static function load(int $idempresa): self
    {
        $raw = [];
        foreach (self::keys() as $key) {
            $raw[$key] = Tools::settings(self::group($idempresa), $key, '');
        }
        return self::fromArray($idempresa, $raw);
    }

    /** @return self[] */
    public static function all(): array
    {
        $out = [];
        foreach ((new Empresa())->all([], ['idempresa' => 'ASC']) as $e) {
            $out[] = self::load($e->idempresa);
        }
        return $out;
    }

    public function save(): void
    {
        $group = self::group($this->idempresa);
        Tools::settings($group, 'enabled', $this->enabled ? '1' : '0');
        Tools::settings($group, 'codserie', $this->codserie);
        Tools::settings($group, 'codcliente', $this->codcliente);
        Tools::settings($group, 'amount', (string)$this->amount);
        Tools::settings($group, 'day', (string)$this->day);
        Tools::settings($group, 'rate_override', (string)$this->rateOverride);
        Tools::settings($group, 'description', $this->description);
        Tools::settingsSave();
    }

    public static function keys(): array
    {
        return ['enabled', 'codserie', 'codcliente', 'amount', 'day', 'rate_override', 'description'];
    }
}
