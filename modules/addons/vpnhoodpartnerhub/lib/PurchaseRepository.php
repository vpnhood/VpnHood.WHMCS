<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

use WHMCS\Database\Capsule;

/**
 * One row per Hub order: the request that bought it, and the last step whose result is
 * confirmed. A request can die after a step succeeded and before its result is saved, so the
 * row alone never proves what the next step did — PurchaseProcessor checks each step's
 * footprint before it redoes anything.
 */
class PurchaseRepository
{
    public const TABLE = 'mod_vpnhood_partner_purchases';

    public const MAX_REFERENCE_LENGTH = 191;
    public const KEY_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /** Admin-only product custom field: AddOrder writes the purchase id onto the service it creates. */
    public const MARKER_FIELD = 'hubPurchaseId';

    public const CREATED = 'created';
    public const ORDERED = 'ordered';
    public const PAID = 'paid';
    public const PROVISIONED = 'provisioned';
    public const DELIVERED = 'delivered';
    public const ROLLED_BACK = 'rolled_back';
    public const NEEDS_RECONCILIATION = 'needs_reconciliation';

    /** States a request passes through; a row left in one of them is a request that died midway. */
    public const IN_FLIGHT = [self::CREATED, self::ORDERED, self::PAID, self::PROVISIONED];

    /** Set once the table exists and the orders placed before it are back-filled. */
    private const READY_SETTING = 'VpnHoodPartnerHubPurchasesReady';

    private static bool $ready = false;

    // -- Initialization -----------------------------------------------------

    /**
     * Create the table and back-fill the orders placed before it existed. The Hub has no
     * upgrade step, so this runs on first use (and from _activate). Every lookup waits for it:
     * a guard reading a half back-filled table could miss a legacy order and buy twice.
     *
     * @return bool false when another request still held the initialization after $waitSeconds
     */
    public function ensureReady(int $waitSeconds): bool
    {
        if (self::$ready || $this->isReady()) {
            self::$ready = true;
            return true;
        }
        if (!$this->lock('init', $waitSeconds)) {
            return false;
        }
        try {
            if (!$this->isReady()) {
                if (!Capsule::schema()->hasTable(self::TABLE)) {
                    $this->createTable();
                }
                $this->backfill();
                \WHMCS\Config\Setting::setValue(self::READY_SETTING, '1');
            }
        } finally {
            $this->unlock('init');
        }
        self::$ready = true;
        return true;
    }

    /** Whether the table is usable. Read-only callers (client area, admin tab) check this instead of initializing. */
    public function isReady(): bool
    {
        // Read directly: the setting must reflect another request's write, not this request's cache.
        return Capsule::table('tblconfiguration')->where('setting', self::READY_SETTING)->value('value') === '1'
            && Capsule::schema()->hasTable(self::TABLE);
    }

    /**
     * Laravel adds each index with its own ALTER after CREATE TABLE, so a failure midway leaves
     * a table without them — which the next request would take as done. The table is new and
     * empty here (under the init lock), so drop it and let the next request start over.
     */
    private function createTable(): void
    {
        try {
            $this->createTableColumns();
        } catch (\Throwable $e) {
            Capsule::schema()->dropIfExists(self::TABLE);
            throw $e;
        }
    }

    private function createTableColumns(): void
    {
        Capsule::schema()->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->integer('partner_id')->unsigned();
            $table->integer('client_id')->unsigned();
            // Binary: two keys that differ only in case are two purchases.
            $table->string('idempotency_key', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('customer_reference', self::MAX_REFERENCE_LENGTH)->nullable();
            $table->integer('product_id')->unsigned();
            $table->string('billing_cycle', 32);
            $table->integer('order_id')->unsigned()->nullable();
            $table->integer('invoice_id')->unsigned()->nullable();
            $table->integer('service_id')->unsigned()->nullable();
            $table->string('state', 32);
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            // Named explicitly: the generated names exceed MariaDB's 64-character limit.
            $table->unique(['partner_id', 'idempotency_key'], 'vhpurchase_partner_key');
            $table->unique('order_id', 'vhpurchase_order');
            $table->index(['partner_id', 'customer_reference'], 'vhpurchase_partner_reference');
            $table->index('state', 'vhpurchase_state');
        });
    }

    /**
     * Record every order the Hub answered before this table existed, from the request log:
     * the logged request carries the reference, the logged response the order ids. Insert-if-
     * absent by order id, so a run that dies midway is resumed by the next one.
     */
    private function backfill(): void
    {
        $rows = Capsule::table('mod_vpnhood_partner_log')
            ->where('action', 'order')
            ->where('http_status', 200)
            ->whereNotNull('partner_id')
            ->orderBy('id')
            ->get(['partner_id', 'request', 'response', 'created_at']);

        foreach ($rows as $row) {
            $request = json_decode((string) $row->request, true);
            $response = json_decode((string) $row->response, true);
            if (!is_array($request) || !is_array($response)) {
                continue;
            }
            $reference = (string) ($request['customerReference'] ?? '');
            if (mb_strlen($reference) > self::MAX_REFERENCE_LENGTH) {
                $reference = '';
            }
            foreach ($response['keys'] ?? [] as $key) {
                $orderId = (int) ($key['upstreamOrderId'] ?? 0);
                if ($orderId <= 0 || Capsule::table(self::TABLE)->where('order_id', $orderId)->exists()) {
                    continue;
                }
                $service = Capsule::table('tblhosting')->where('orderid', $orderId)
                    ->first(['id', 'userid', 'packageid', 'billingcycle']);
                if ($service === null) {
                    continue; // deleted since: nothing left to protect or link
                }
                Capsule::table(self::TABLE)->insert([
                    'partner_id'         => (int) $row->partner_id,
                    'client_id'          => (int) $service->userid,
                    'idempotency_key'    => null,
                    'customer_reference' => $reference === '' ? null : $reference,
                    'product_id'         => (int) $service->packageid,
                    'billing_cycle'      => self::normalizeCycle((string) $service->billingcycle),
                    'order_id'           => $orderId,
                    'invoice_id'         => (int) Capsule::table('tblorders')->where('id', $orderId)->value('invoiceid') ?: null,
                    'service_id'         => (int) $service->id,
                    'state'              => self::DELIVERED,
                    'created_at'         => $row->created_at,
                    'updated_at'         => $row->created_at,
                ]);
            }
        }
    }

    /**
     * The cycle a request resolves to (`monthly`, …, `onetime`) from a service's stored cycle
     * (`Monthly`, `Semi-Annually`, `One Time`, `Free Account`), so the two compare.
     */
    public static function normalizeCycle(string $cycle): string
    {
        $cycle = strtolower(str_replace(['-', ' '], '', $cycle));
        return in_array($cycle, ['onetime', 'freeaccount', 'free'], true) ? 'onetime' : $cycle;
    }

    // -- Named locks ----------------------------------------------------------

    /**
     * MariaDB named lock. The server drops it if the request dies, so a crash never leaves a
     * key locked. Names are server-wide and this MariaDB serves more than one WHMCS: the
     * database name keeps them apart.
     */
    public function lock(string $name, int $timeoutSeconds): bool
    {
        $acquired = Capsule::connection()->selectOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [$this->lockName($name), $timeoutSeconds]
        )->acquired;
        if ($acquired === null) {
            throw new \RuntimeException("GET_LOCK failed for '{$name}'.");
        }
        return (int) $acquired === 1;
    }

    public function unlock(string $name): void
    {
        Capsule::connection()->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->lockName($name)]);
    }

    private function lockName(string $name): string
    {
        return 'vhhub-' . md5(Capsule::connection()->getDatabaseName() . "\0" . $name);
    }

    public static function keyLock(int $partnerId, string $key): string
    {
        return "key\0{$partnerId}\0{$key}";
    }

    /** Credit belongs to the WHMCS client, and several partner records may share one client. */
    public static function creditLock(int $clientId): string
    {
        return "credit\0{$clientId}";
    }

    // -- Rows -----------------------------------------------------------------

    public function find(int $id): ?array
    {
        $row = Capsule::table(self::TABLE)->where('id', $id)->first();
        return $row ? (array) $row : null;
    }

    public function findByKey(int $partnerId, string $key): ?array
    {
        $row = Capsule::table(self::TABLE)->where('partner_id', $partnerId)->where('idempotency_key', $key)->first();
        return $row ? (array) $row : null;
    }

    public function findByOrder(int $partnerId, int $orderId): ?array
    {
        $row = Capsule::table(self::TABLE)->where('partner_id', $partnerId)->where('order_id', $orderId)->first();
        return $row ? (array) $row : null;
    }

    public function create(array $partner, ?string $key, string $reference, int $productId, string $billingCycle): array
    {
        $now = date('Y-m-d H:i:s');
        $id = Capsule::table(self::TABLE)->insertGetId([
            'partner_id'         => (int) $partner['id'],
            'client_id'          => (int) $partner['client_id'],
            'idempotency_key'    => $key,
            'customer_reference' => $reference === '' ? null : $reference,
            'product_id'         => $productId,
            'billing_cycle'      => $billingCycle,
            'state'              => self::CREATED,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        return $this->find((int) $id);
    }

    /**
     * Move a purchase from $from to $to with $fields. Fails loudly when the row is not in
     * $from: no step may run on a state it did not read.
     */
    public function advance(int $id, string $from, string $to, array $fields = []): array
    {
        $affected = Capsule::table(self::TABLE)->where('id', $id)->where('state', $from)
            ->update(array_merge($fields, ['state' => $to, 'updated_at' => date('Y-m-d H:i:s')]));
        if ($affected !== 1) {
            throw new \RuntimeException("Purchase #{$id} is no longer in state '{$from}'.");
        }
        return $this->find($id);
    }

    /** Overwrite the state without a precondition — only for a person's decision (admin Retry/Release). */
    public function setState(int $id, string $state, array $fields = []): array
    {
        Capsule::table(self::TABLE)->where('id', $id)
            ->update(array_merge($fields, ['state' => $state, 'updated_at' => date('Y-m-d H:i:s')]));
        return $this->find($id);
    }

    public function noteError(int $id, string $error): void
    {
        Capsule::table(self::TABLE)->where('id', $id)
            ->update(['last_error' => mb_substr($error, 0, 2000), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Atomically bind a legacy (keyless) order to a key: exactly one of any number of
     * concurrent claims can match `idempotency_key IS NULL`.
     */
    public function claim(int $id, int $partnerId, string $key): bool
    {
        return Capsule::table(self::TABLE)
            ->where('id', $id)->where('partner_id', $partnerId)->whereNull('idempotency_key')
            ->update(['idempotency_key' => $key, 'updated_at' => date('Y-m-d H:i:s')]) === 1;
    }

    /** A rolled-back key is free again: the same row starts over as a new purchase. */
    public function restart(int $id): array
    {
        return $this->advance($id, self::ROLLED_BACK, self::CREATED, [
            'order_id' => null, 'invoice_id' => null, 'service_id' => null, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Live keyless orders of this partner under a customerReference — what a keyed request
     * must not buy over without asking (a response lost under an older connector).
     */
    public function legacyCandidates(int $partnerId, int $clientId, string $reference): array
    {
        $rows = Capsule::table(self::TABLE . ' as p')
            ->join('tblhosting as h', 'h.id', '=', 'p.service_id')
            ->leftJoin('tblproducts as pr', 'pr.id', '=', 'p.product_id')
            ->where('p.partner_id', $partnerId)
            ->whereNull('p.idempotency_key')
            ->where('p.customer_reference', $reference)
            ->where('h.userid', $clientId)
            ->whereIn('h.domainstatus', ['Pending', 'Active', 'Suspended'])
            ->orderBy('p.order_id')
            ->get(['p.order_id', 'pr.name as product', 'p.billing_cycle', 'h.domainstatus', 'p.created_at']);

        return array_map(fn ($r) => [
            'upstreamOrderId' => (int) $r->order_id,
            'product'         => (string) $r->product,
            'billingCycle'    => (string) $r->billing_cycle,
            'status'          => (string) $r->domainstatus,
            'placedAt'        => (string) $r->created_at,
        ], $rows->all());
    }

    /**
     * Purchases a person must look at: needs_reconciliation, and requests that died midway
     * (a keyed repeat resumes those; a keyless one never comes back).
     */
    public function needingAttention(?int $partnerId, int $staleMinutes = 10): array
    {
        if (!$this->isReady()) {
            return [];
        }
        $stale = date('Y-m-d H:i:s', time() - $staleMinutes * 60);
        $query = Capsule::table(self::TABLE)
            ->where(function ($q) use ($stale) {
                $q->where('state', self::NEEDS_RECONCILIATION)
                    ->orWhere(function ($q) use ($stale) {
                        $q->whereIn('state', self::IN_FLIGHT)->where('updated_at', '<', $stale);
                    });
            })
            ->orderBy('id');
        if ($partnerId !== null) {
            $query->where('partner_id', $partnerId);
        }
        return array_map(fn ($r) => (array) $r, $query->get()->all());
    }

    // -- Footprints -------------------------------------------------------------

    /** The marker field of a product, created on first use (admin-only, so clients never see it). */
    public function markerFieldId(int $productId): int
    {
        $id = $this->findMarkerField($productId);
        if ($id !== null) {
            return $id;
        }
        if (!$this->lock("field\0{$productId}", 10)) {
            throw new \RuntimeException("Could not lock the purchase marker field of product #{$productId}.");
        }
        try {
            $id = $this->findMarkerField($productId);
            if ($id === null) {
                $now = date('Y-m-d H:i:s');
                $id = (int) Capsule::table('tblcustomfields')->insertGetId([
                    'type' => 'product', 'relid' => $productId, 'fieldname' => self::MARKER_FIELD,
                    'fieldtype' => 'text', 'description' => 'Partner Hub purchase id (set by the Hub; do not edit)',
                    'fieldoptions' => '', 'regexpr' => '', 'adminonly' => 'on', 'required' => '',
                    'showorder' => '', 'showinvoice' => '', 'sortorder' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        } finally {
            $this->unlock("field\0{$productId}");
        }
        return $id;
    }

    private function findMarkerField(int $productId): ?int
    {
        $id = Capsule::table('tblcustomfields')
            ->where('type', 'product')->where('relid', $productId)
            ->whereRaw("SUBSTRING_INDEX(fieldname, '|', 1) = ?", [self::MARKER_FIELD])
            ->orderBy('id')->value('id');
        return $id ? (int) $id : null;
    }

    /**
     * The service AddOrder created for this purchase, found by the marker WHMCS wrote onto it.
     * A key that started over after a rollback can have an older, cancelled attempt too: the
     * newest live one is the attempt that counts.
     */
    public function markedService(int $purchaseId, int $clientId): ?array
    {
        $row = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->join('tblhosting as h', 'h.id', '=', 'v.relid')
            ->leftJoin('tblorders as o', 'o.id', '=', 'h.orderid')
            ->where('f.type', 'product')
            ->whereRaw("SUBSTRING_INDEX(f.fieldname, '|', 1) = ?", [self::MARKER_FIELD])
            ->where('v.value', (string) $purchaseId)
            ->where('h.userid', $clientId)
            ->where(function ($q) {
                $q->whereNull('o.status')->orWhereNotIn('o.status', ['Cancelled', 'Fraud']);
            })
            ->orderBy('h.id', 'desc')
            ->first(['h.id as service_id', 'h.orderid as order_id', 'o.invoiceid as invoice_id']);
        return $row ? (array) $row : null;
    }

    /** Orders of the client since $since that no purchase accounts for. */
    public function unexplainedOrderIds(int $clientId, string $since): array
    {
        return Capsule::table('tblorders as o')
            ->leftJoin(self::TABLE . ' as p', 'p.order_id', '=', 'o.id')
            ->where('o.userid', $clientId)
            ->where('o.date', '>=', $since)
            ->whereNull('p.id')
            ->orderBy('o.id')
            ->pluck('o.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
