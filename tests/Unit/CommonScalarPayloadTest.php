<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Chronicle\Process\Data\CommonScalarPayload;
use Chronicle\Process\Exceptions\InvalidPayloadException;
use Illuminate\Support\Carbon as LaravelCarbon;

it('round trips supported date types through JSON without losing precision or timezone', function (string $class, string $timezone): void {
    $original = new $class('2026-01-15 12:34:56.123456', new DateTimeZone($timezone));
    $before = $original->format('Y-m-d H:i:s.u e');
    $json = json_encode(CommonScalarPayload::make($original), JSON_THROW_ON_ERROR);

    $restored = CommonScalarPayload::fromPayload(json_decode($json, true, 512, JSON_THROW_ON_ERROR))->value();

    expect($restored::class)->toBe($class)
        ->and($restored)->not->toBe($original)
        ->and($restored->format('U.u'))->toBe($original->format('U.u'))
        ->and($restored->format('e'))->toBe($timezone)
        ->and($original->format('Y-m-d H:i:s.u e'))->toBe($before);
})->with([
    'native mutable' => [DateTime::class],
    'native immutable' => [DateTimeImmutable::class],
    'Carbon' => [Carbon::class],
    'CarbonImmutable' => [CarbonImmutable::class],
    'Laravel Carbon' => [LaravelCarbon::class],
])->with(['America/New_York', 'UTC', '+05:30']);

it('preserves timezone rules for later date arithmetic', function (string $class): void {
    $original = new $class('2026-01-15 12:34:56.123456', new DateTimeZone('America/New_York'));
    $payload = CommonScalarPayload::make($original)->jsonSerialize();

    $restored = CommonScalarPayload::fromPayload($payload)->value();
    $summer = $restored->modify('+6 months');

    expect($summer->format('Y-m-d H:i:s.uP'))->toBe('2026-07-15 12:34:56.123456-04:00')
        ->and($summer->format('e'))->toBe('America/New_York');
})->with([DateTime::class, DateTimeImmutable::class, Carbon::class, CarbonImmutable::class, LaravelCarbon::class]);

it('preserves the selected occurrence of an ambiguous daylight saving time', function (): void {
    $original = (new DateTimeImmutable('2026-11-01T01:30:00.654321-05:00'))
        ->setTimezone(new DateTimeZone('America/New_York'));

    $restored = CommonScalarPayload::fromPayload(CommonScalarPayload::make($original)->jsonSerialize())->value();

    expect($restored::class)->toBe(DateTimeImmutable::class)
        ->and($restored->format('U.u'))->toBe($original->format('U.u'))
        ->and($restored->format('Y-m-d H:i:s.uP e'))->toBe('2026-11-01 01:30:00.654321-05:00 America/New_York');
});

it('reads existing datetime payloads using their original Carbon behavior', function (): void {
    $restored = CommonScalarPayload::fromPayload([
        'k' => 'datetime',
        'v' => '2026-01-15T12:34:56.123456-05:00',
    ])->value();

    expect($restored::class)->toBe(Carbon::class)
        ->and($restored->format(CommonScalarPayload::DATETIME_FORMAT))->toBe('2026-01-15T12:34:56.123456-05:00');
});

it('rejects unsupported date subclasses instead of silently changing their type', function (): void {
    $date = new class('2026-01-15T12:34:56+00:00') extends DateTimeImmutable {};

    expect(fn () => CommonScalarPayload::make($date))->toThrow(InvalidPayloadException::class);
});

it('rejects invalid serialized datetime data', function (array $data): void {
    expect(fn () => CommonScalarPayload::fromPayload($data)->value())->toThrow(InvalidPayloadException::class);
})->with([
    'unknown type' => [[
        'k' => 'datetime', 'date_type' => 'unknown', 'v' => '2026-01-15T12:34:56.123456+00:00',
    ]],
    'non-string type' => [[
        'k' => 'datetime', 'date_type' => 42, 'v' => '2026-01-15T12:34:56.123456+00:00',
    ]],
    'missing value' => [['k' => 'datetime', 'date_type' => 'datetime_immutable']],
    'non-string value' => [['k' => 'datetime', 'v' => 42]],
    'invalid date' => [['k' => 'datetime', 'v' => 'not-a-date']],
    'impossible date' => [['k' => 'datetime', 'v' => '2026-02-31T12:34:56.123456+00:00']],
    'invalid timezone' => [[
        'k' => 'datetime', 'v' => '2026-01-15T12:34:56.123456+00:00', 'timezone' => 'Invalid/Timezone',
    ]],
    'non-string timezone' => [[
        'k' => 'datetime', 'v' => '2026-01-15T12:34:56.123456+00:00', 'timezone' => 42,
    ]],
]);
