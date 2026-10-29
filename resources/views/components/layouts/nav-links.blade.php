@foreach ($links as $link)
    <a href="{{ $link['href'] }}" @class(['nav-link', 'active' => $link['active']]) @if($link['active']) aria-current="page" @endif>
        <x-icon :name="$link['icon']"/> <span>{{ $link['label'] }}</span>
    </a>
@endforeach
