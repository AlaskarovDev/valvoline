<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :own="['only']">
        <label class="flex items-center gap-1.5 text-xs text-muted">
            <input type="checkbox" name="only" value="loss" @checked(request('only') === 'loss') onchange="this.form.submit()" class="size-4">
            Yalnız zərərlilər ({{ $lossCount }})
        </label>
    </x-filters>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
