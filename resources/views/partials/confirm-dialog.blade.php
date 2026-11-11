{{-- Accessible replacement for window.confirm(). Triggered by [data-confirm] on a form/button, or $store.confirm.ask(). --}}
<div x-data x-cloak x-show="$store.confirm.open" class="fixed inset-0 z-[330] grid place-items-center p-4" role="alertdialog" aria-modal="true"
     aria-labelledby="confirm-title" aria-describedby="confirm-text" @keydown.escape.window="$store.confirm.cancel()"
     x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-100" x-transition:leave-end="opacity-0">
    <div class="absolute inset-0 dialog-scrim" @click="$store.confirm.cancel()"></div>
    <div class="dialog-surface relative w-full max-w-sm rounded-lg p-6 shadow-lift" tabindex="-1" x-trap="$store.confirm.open">
        <h2 id="confirm-title" class="!text-lg" x-text="$store.confirm.title"></h2>
        <p id="confirm-text" class="muted text-sm" x-text="$store.confirm.message"></p>
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" class="btn btn--outline btn--sm" data-autofocus @click="$store.confirm.cancel()">Keep it</button>
            <button type="button" class="btn btn--sm" @click="$store.confirm.accept()" x-text="$store.confirm.label"></button>
        </div>
    </div>
</div>
