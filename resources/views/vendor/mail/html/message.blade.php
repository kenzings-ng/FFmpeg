<x-mail::layout>
{{-- Header --}}
<x-slot:header>
{{-- Trỏ về FE (nơi người dùng thực sự dùng sản phẩm), không phải app.url
     (domain của API, bấm vào chỉ thấy JSON/trang trống). --}}
<x-mail::header :url="config('services.frontend.url')">
<span style="color: #f43f5e;">▶</span> {{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. Đã đăng ký bản quyền.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
