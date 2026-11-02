<x-mail::message>
# ご注文を受け付けました

{{ $order->user?->name }} 様、ご注文ありがとうございます。

@include('mail.shop._summary')

<x-mail::button :url="route('orders.show', $order)">
注文の詳細を見る
</x-mail::button>
</x-mail::message>
