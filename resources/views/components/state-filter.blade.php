{{-- Sənəd siyahısında vəziyyət: aktiv / ləğv edilmiş (x-filters slotunda, :own="['state']") --}}
<select name="state" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()" aria-label="{{ __('Sənədin vəziyyəti') }}">
    <option value="">{{ __('Aktiv sənədlər') }}</option>
    <option value="cancelled" @selected(request('state') === 'cancelled')>{{ __('Ləğv edilmişlər') }}</option>
</select>
