@props(['action', 'confirm' => null, 'label' => null])
{{-- Sənədi ləğv et: səbəb soruşulur; sənəd silinmir, «ləğv edilib» kimi qalır --}}
<div x-data class="contents">
    <button type="button" class="btn btn-sm btn-danger" @click="$refs.cancelDialog.showModal()"><x-icon name="trash" size="14"/> {{ $label ?? __('Ləğv et') }}</button>
    <dialog x-ref="cancelDialog" aria-label="{{ __('Sənədi ləğv et') }}"
            class="m-auto w-[min(480px,92vw)] rounded-xl border border-line bg-surface p-0 text-ink shadow-2xl backdrop:bg-black/50">
        <form method="POST" action="{{ $action }}" class="flex flex-col gap-3 p-5">
            @csrf @method('DELETE')
            <h2 class="text-base font-semibold">{{ __('Sənədi ləğv et') }}</h2>
            @if ($confirm)<p class="text-sm">{{ $confirm }}</p>@endif
            <div>
                <label class="label" for="cancel-reason-{{ md5($action) }}">{{ __('Ləğv səbəbi') }}</label>
                <textarea id="cancel-reason-{{ md5($action) }}" name="reason" rows="3" required minlength="3" maxlength="500" class="input h-auto py-2"
                          placeholder="{{ __('Məs.: səhv müştəri seçilib, sənəd təkrar yazılıb') }}"></textarea>
            </div>
            <p class="text-xs text-muted">{{ __('Sənəd silinmir: nömrəsi və sətirləri «ləğv edilib» kimi qalır, kim və nə vaxt ləğv etdiyi audit jurnalına yazılır.') }}</p>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-sm" @click="$refs.cancelDialog.close()">{{ __('İmtina') }}</button>
                <button class="btn btn-sm btn-danger">{{ __('Ləğv et') }}</button>
            </div>
        </form>
    </dialog>
</div>
