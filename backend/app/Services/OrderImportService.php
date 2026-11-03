<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Orders from a CSV file: one row per order line, rows with the same order_ref make one order. The whole file is
 * checked first and nothing is created when any row is wrong, so a file can be fixed and sent again.
 */
class OrderImportService
{
    public const COLUMNS = [
        'order_ref', 'customer_name', 'customer_email', 'customer_phone', 'address1', 'city', 'province', 'zip',
        'country_code', 'sku', 'quantity', 'unit_price', 'currency', 'payment_status', 'notes',
    ];

    public const REQUIRED_COLUMNS = ['order_ref', 'customer_name', 'sku', 'quantity', 'unit_price', 'currency'];

    public const MAX_ROWS = 1000;

    public function __construct(
        protected OrderService $orders
    ) {}

    /**
     * @return list<string> numbers of the orders created
     *
     * @throws ValidationException listing every problem by row
     */
    public function import(UploadedFile $file, User $user, ?int $storeId): array
    {
        $organizationId = $user->currentOrganization()->id;
        [$rows, $errors] = $this->read($file);
        $variants = ProductVariant::where('organization_id', $organizationId)
            ->whereIn(DB::raw('LOWER(sku)'), array_unique(array_map('strtolower', array_column($rows, 'sku'))))
            ->pluck('id', 'sku')
            ->mapWithKeys(fn (int $id, string $sku) => [strtolower($sku) => $id]);

        $orders = [];
        foreach ($rows as $line => $row) {
            $variantId = $variants[strtolower($row['sku'])] ?? null;
            if ($variantId === null) {
                $errors[] = "Row {$line}: no product has SKU {$row['sku']}.";

                continue;
            }
            $orders[$row['order_ref']] ??= ['first_row' => $line, 'data' => $this->orderFields($row, $storeId)];
            $orders[$row['order_ref']]['data']['items'][] = ['product_variant_id' => $variantId, 'quantity' => $row['quantity'], 'unit_price' => $row['unit_price']];
        }

        $rules = $this->orders->rules($organizationId);
        foreach ($orders as $ref => $order) {
            $check = Validator::make($order['data'], $rules, OrderService::VALIDATION_MESSAGES);
            foreach ($check->errors()->all() as $message) {
                $errors[] = "Row {$order['first_row']} (order {$ref}): {$message}";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 50)]);
        }
        if ($orders === []) {
            throw ValidationException::withMessages(['file' => 'The file has no order lines.']);
        }

        return array_values(array_map(function (array $order) use ($user) {
            $order['data']['currency'] = strtoupper($order['data']['currency']);

            return $this->orders->create($user, $order['data'])[0]->order_number;
        }, $orders));
    }

    /**
     * Rows by their line number in the file (the header is line 1), with problems in the header or row shape.
     *
     * @return array{0: array<int, array<string, string>>, 1: list<string>}
     */
    private function read(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = array_map(fn ($name) => strtolower(trim((string) $name, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($handle) ?: []);
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ($missing !== []) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'The file is missing the column(s): '.implode(', ', $missing).'.']);
        }

        $rows = [];
        $errors = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if ($values === [null] || implode('', $values) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = 'The file has more than '.self::MAX_ROWS.' lines; split it into smaller files.';
                break;
            }
            if (count($values) !== count($header)) {
                $errors[] = "Row {$line}: expected ".count($header).' values but found '.count($values).'.';

                continue;
            }
            $rows[$line] = array_map(fn ($value) => trim((string) $value), array_combine($header, $values));
        }
        fclose($handle);

        return [$rows, $errors];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function orderFields(array $row, ?int $storeId): array
    {
        $address = array_filter([
            'address1' => $row['address1'] ?? null,
            'city' => $row['city'] ?? null,
            'province' => $row['province'] ?? null,
            'zip' => $row['zip'] ?? null,
            'country_code' => isset($row['country_code']) && $row['country_code'] !== '' ? strtoupper($row['country_code']) : null,
        ], fn ($value) => $value !== null && $value !== '');

        return array_filter([
            'store_id' => $storeId,
            'customer_name' => $row['customer_name'],
            'customer_email' => $row['customer_email'] ?? null,
            'customer_phone' => $row['customer_phone'] ?? null,
            'shipping_address' => $address ?: null,
            'currency' => $row['currency'],
            'payment_status' => strtolower(($row['payment_status'] ?? '') ?: 'pending'),
            'notes' => $row['notes'] ?? null,
            'items' => [],
        ], fn ($value) => $value !== null && $value !== '');
    }
}
