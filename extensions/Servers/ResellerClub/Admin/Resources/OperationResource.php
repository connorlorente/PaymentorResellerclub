<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Admin\Resources;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\ResellerClub\Admin\Resources\OperationResource\Pages\ListOperations;
use Paymenter\Extensions\Servers\ResellerClub\Core\OperationRetry;
use Paymenter\Extensions\Servers\ResellerClub\Core\Redactor;
use Paymenter\Extensions\Servers\ResellerClub\Jobs\RegisterDomainJob;
use Paymenter\Extensions\Servers\ResellerClub\Jobs\RenewDomainJob;
use Paymenter\Extensions\Servers\ResellerClub\Models\EloquentOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Models\Operation;

class OperationResource extends Resource
{
    protected static ?string $model = Operation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-global-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Domain operations';

    protected static ?string $modelLabel = 'domain operation';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('service_id')->label('Service')->sortable()->searchable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable()->color(fn (string $state) => match ($state) {
                    'completed' => 'success',
                    'failed' => 'danger',
                    'submitted' => 'info',
                    default => 'gray',
                }),
                TextColumn::make('rc_order_id')->label('Order ID')->searchable(),
                TextColumn::make('attempts'),
                TextColumn::make('last_error')->label('Error')->wrap()->limit(120)->tooltip(fn ($record) => $record->last_error),
                // Redacted again on read, in case an older row was stored before a secret key was known.
                TextColumn::make('payload')->state(fn (Operation $record) => json_encode(Redactor::payload($record->payload ?? [])))
                    ->limit(80)->tooltip(fn (Operation $record) => json_encode(Redactor::payload($record->payload ?? []), JSON_PRETTY_PRINT))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(['queued', 'submitted', 'completed', 'failed'], ['Queued', 'Submitted', 'Completed', 'Failed'])),
                SelectFilter::make('type')->options(['register' => 'Register', 'renew' => 'Renew', 'nameservers' => 'Nameservers']),
            ])
            ->recordActions([
                Action::make('retry')
                    ->icon('ri-restart-line')
                    ->requiresConfirmation()
                    ->authorize('update')
                    ->visible(fn (Operation $record) => (new OperationRetry(new EloquentOperationStore()))->canRetry($record->toArray()))
                    ->action(function (Operation $record) {
                        $type = (new OperationRetry(new EloquentOperationStore()))->reset($record->idempotency_key);
                        match ($type) {
                            'register' => RegisterDomainJob::dispatch($record->service_id, $record->idempotency_key),
                            'renew' => RenewDomainJob::dispatch($record->service_id, $record->idempotency_key),
                        };
                        Notification::make()->title('Retry queued')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperations::route('/'),
        ];
    }
}
