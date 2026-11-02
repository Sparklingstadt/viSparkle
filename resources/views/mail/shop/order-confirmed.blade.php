<x-mail::message>
# ご注文を受け付けました

{{ $order->user?->name }} 様、ご注文ありがとうございます。

@include('mail.shop._summary')

@if ($order->hasDigital())
デジタル作品は、注文の画面からダウンロードできます。作品が非公開になるとダウンロードできなくなるため、早めに保存してください。
@endif

<x-mail::button :url="route('orders.show', $order)">
注文の詳細を見る
</x-mail::button>
</x-mail::message>
