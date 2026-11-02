# Routing & Controllers Best Practices

## Use Implicit Route Model Binding

Let Laravel resolve models automatically from route parameters.

Incorrect:
```php
public function show(int $id)
{
    $post = Post::findOrFail($id);
}
```

Correct:
```php
public function show(Post $post)
{
    return view('posts.show', ['post' => $post]);
}
```

## Use Scoped Bindings for Nested Resources

Enforce parent-child relationships automatically.

```php
Route::get('/users/{user}/posts/{post}', function (User $user, Post $post) {
    // $post is automatically scoped to $user
})->scopeBindings();
```

## Use Resource Controllers

Use `Route::resource()` or `apiResource()` for RESTful endpoints.

```php
Route::resource('posts', PostController::class);
// In routes/api.php — the /api prefix is applied automatically
Route::apiResource('posts', Api\PostController::class);
```

## Keep Query Construction in Custom Model Builders

Controllers handle validated input, authorization, orchestration, and responses. Define query filters, sorting, eager loading, selected columns, and pagination limits in model-specific builders under `app/Models/Builders/`. Accept typed domain values in builder methods, not HTTP requests. Bind each custom builder with `#[UseEloquentBuilder(...)]` and declare `@method static ... query()` on the model, following `GameBuilder` and `ChatMessageBuilder`.

A controller may execute a prepared query, for example:

```php
$messages = ChatMessage::query()
    ->historyPageFor($game, $request->beforeId(), $request->afterId())
    ->get();
```

Keep mutations and transactions in domain actions. Do not move query-building chains into controller helper methods or introduce repositories to bypass this convention.

## Keep Controllers Thin

Aim for under 10 lines per method. Extract business logic to action or service classes.

Incorrect:
```php
public function store(Request $request)
{
    $validated = $request->validate([...]);
    if ($request->hasFile('image')) {
        $request->file('image')->move(public_path('images'));
    }
    $post = Post::create($validated);
    $post->tags()->sync($validated['tags']);
    event(new PostCreated($post));
    return redirect()->route('posts.show', $post);
}
```

Correct:
```php
public function store(StorePostRequest $request, CreatePostAction $create)
{
    $post = $create->execute($request->validated());

    return redirect()->route('posts.show', $post);
}
```

## Type-Hint Form Requests

Type-hinting Form Requests triggers automatic validation and authorization before the method executes.

Incorrect:
```php
public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'title' => ['required', 'max:255'],
        'body' => ['required'],
    ]);

    Post::create($validated);

    return redirect()->route('posts.index');
}
```

Correct:
```php
public function store(StorePostRequest $request, CreatePostAction $create): RedirectResponse
{
    $post = $create->execute($request->validated());

    return to_route('posts.show', $post);
}
```
