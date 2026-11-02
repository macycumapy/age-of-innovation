# Eloquent Best Practices

## Use Correct Relationship Types

Use `hasMany`, `belongsTo`, `morphMany`, etc. with proper return type hints.

```php
public function comments(): HasMany
{
    return $this->hasMany(Comment::class);
}

public function author(): BelongsTo
{
    return $this->belongsTo(User::class, 'user_id');
}
```

## Use Custom Model Builders for Query Construction

This project keeps query construction in `app/Models/Builders/`, following `GameBuilder` and `SettingBuilder`. Add descriptive methods for use cases such as `historyPageFor()` or `visibleSummariesFor()`. Keep HTTP request objects out of builders; pass models, DTOs, and typed scalar values. Keep query pagination constants, such as `PAGE_SIZE`, in the builder that uses them.

```php
/** @extends Builder<ChatMessage> */
final class ChatMessageBuilder extends Builder
{
    public const int PAGE_SIZE = 50;

    public function historyPageFor(Game $game, ?int $beforeId = null, ?int $afterId = null): self
    {
        return $this
            ->whereBelongsTo($game)
            ->when($beforeId !== null, fn (self $query): self => $query->where('id', '<', $beforeId))
            ->when($afterId !== null, fn (self $query): self => $query->where('id', '>', $afterId))
            ->orderBy('id', $afterId !== null ? 'asc' : 'desc')
            ->limit(self::PAGE_SIZE + 1);
    }
}
```

Register the builder with `#[UseEloquentBuilder(ChatMessageBuilder::class)]` on the model and add `@method static ChatMessageBuilder query()` to its PHPDoc. Controllers call these descriptive methods instead of building query chains themselves.

## Apply Global Scopes Sparingly

Global scopes silently modify every query on the model, making debugging difficult. Keep conditional constraints in custom builder methods and reserve global scopes for truly universal constraints like soft deletes or multi-tenancy.

Incorrect (global scope for a conditional filter):
```php
class PublishedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('published', true);
    }
}
// Now admin panels, reports, and background jobs all silently skip drafts
```

Correct (local scope you opt into):
```php
public function scopePublished(Builder $query): Builder
{
    return $query->where('published', true);
}

Post::published()->paginate(); // Explicit
Post::paginate(); // Admin sees all
```

## Define Attribute Casts

Use the `casts()` method (or `$casts` property following project convention) for automatic type conversion.

```php
protected function casts(): array
{
    return [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'total' => 'decimal:2',
    ];
}
```

## Cast Date Columns Properly

Always cast date columns. Use Carbon instances in templates instead of formatting strings manually.

Incorrect:
```blade
{{ Carbon::createFromFormat('Y-d-m H-i', $order->ordered_at)->toDateString() }}
```

Correct:
```php
protected function casts(): array
{
    return [
        'ordered_at' => 'datetime',
    ];
}
```

```blade
{{ $order->ordered_at->toDateString() }}
{{ $order->ordered_at->format('m-d') }}
```

## Use `whereBelongsTo()` for Relationship Queries

Cleaner than manually specifying foreign keys.

Incorrect:
```php
Post::where('user_id', $user->id)->get();
```

Correct:
```php
Post::whereBelongsTo($user)->get();
Post::whereBelongsTo($user, 'author')->get();
```

## Avoid Hardcoded Table Names in Queries

Never use string literals for table names in raw queries, joins, or subqueries. Hardcoded table names make it impossible to find all places a model is used and break refactoring (e.g., renaming a table requires hunting through every raw string).

Incorrect:
```php
DB::table('users')->where('active', true)->get();

$query->join('companies', 'companies.id', '=', 'users.company_id');

DB::select('SELECT * FROM orders WHERE status = ?', ['pending']);
```

Correct — reference the model's table:
```php
DB::table((new User)->getTable())->where('active', true)->get();

// Even better — use Eloquent or the query builder instead of raw SQL
User::where('active', true)->get();
Order::where('status', 'pending')->get();
```

Prefer Eloquent queries and relationships over `DB::table()` whenever possible — they already reference the model's table. When `DB::table()` or raw joins are unavoidable, always use `(new Model)->getTable()` to keep the reference traceable.

**Exception — migrations:** In migrations, hardcoded table names via `DB::table('settings')` are acceptable and preferred. Models change over time but migrations are frozen snapshots — referencing a model that is later renamed or deleted would break the migration.
