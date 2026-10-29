<div>
    <label class="label" for="p-{{ $name }}">{{ $label }}</label>
    <select id="p-{{ $name }}" name="{{ $name }}" class="select" @required($required)>
        <option value="">—</option>
        @foreach ($list as $item)<option value="{{ $item->id }}" @selected((string) $selected === (string) $item->id)>{{ $item->name }}</option>@endforeach
    </select>
</div>
