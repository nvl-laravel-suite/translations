<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Services\SourceTranslationWorkspace;

it('authorizes source translation work against current persisted actor privileges', function (): void {
    Schema::create('source_translation_actor_test_users', static function (Blueprint $table): void {
        $table->id();
        $table->boolean('can_translate')->default(false);
    });
    $actor = SourceTranslationActorTestUser::query()->create(['can_translate' => false]);
    $actor->forceFill(['can_translate' => true]);
    app()->instance(TranslationsAuthorization::class, new class implements TranslationsAuthorization
    {
        public function authorize(TranslationsAbility $ability, Authenticatable|SystemActor $actor, ?TranslationEntry $entry = null): void
        {
            if (! $actor instanceof Model || ! $actor->getAttribute('can_translate')) {
                throw new AuthorizationException;
            }
        }
    });
    expect(fn () => app(SourceTranslationWorkspace::class)->authorize($actor, TranslationsAbility::ListEntries))->toThrow(AuthorizationException::class);
    $actor->newQuery()->whereKey($actor->getKey())->update(['can_translate' => true]);
    app(SourceTranslationWorkspace::class)->authorize($actor, TranslationsAbility::ListEntries);
    $actor->newQuery()->whereKey($actor->getKey())->delete();
    expect(fn () => app(SourceTranslationWorkspace::class)->authorize($actor, TranslationsAbility::ListEntries))->toThrow(AuthorizationException::class);
});

it('retains the persisted Eloquent actor requirement for source translation work', function (): void {
    expect(fn () => app(SourceTranslationWorkspace::class)->authorize(new GenericUser(['id' => 'directory-user']), TranslationsAbility::ListEntries))->toThrow(AuthorizationException::class);
});

/** Persisted host actor used by the source translation policy. */
final class SourceTranslationActorTestUser extends User
{
    protected $table = 'source_translation_actor_test_users';

    protected $guarded = [];

    public $timestamps = false;
}
