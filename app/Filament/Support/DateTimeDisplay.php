<?php

declare(strict_types=1);

namespace App\Filament\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Table;

/**
 * The single place that decides how a timestamp is shown in the admin panel
 * (PROJECT.md §9, docs/DECISIONS.md D-046): stored UTC instants are converted
 * to `app.display_timezone` (Europe/Malta) and always carry the zone
 * abbreviation (CET / CEST). Storage and calculations stay in UTC.
 */
final class DateTimeDisplay
{
    public const string FORMAT = 'Y-m-d H:i T';

    public static function timezone(): string
    {
        $timezone = config('app.display_timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * "2026-10-06 10:30 CEST" for an instant; the placeholder for null.
     */
    public static function format(?DateTimeInterface $time, string $placeholder = '—'): string
    {
        if ($time === null) {
            return $placeholder;
        }

        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone(self::timezone()))
            ->format(self::FORMAT);
    }

    /**
     * Makes every Filament `dateTime()` entry/column (and date-time picker)
     * render in the display timezone with the same format, so resources
     * need no per-field timezone.
     */
    public static function configureFilament(): void
    {
        FilamentTimezone::set(fn (): string => self::timezone());
        Table::configureUsing(fn (Table $table): Table => $table->defaultDateTimeDisplayFormat(self::FORMAT));
        Schema::configureUsing(fn (Schema $schema): Schema => $schema->defaultDateTimeDisplayFormat(self::FORMAT));
    }
}
