# Notification event matrix (Stage 14.4)

Queued events use `NotificationDispatcher` → `NotificationQueueService::enqueue()`. The cron worker (`cron/process_notification_queue.php`) delivers them asynchronously.

## Integrated events

| Event type | Channel | Template key | Idempotency key | Trigger |
|------------|---------|--------------|-----------------|---------|
| `order.placed` | email | `order.placed.email` | `order.placed:order:{orderId}:email` | `CheckoutService::placeOrderDb()` after order insert (`NotificationDispatcher::orderPlacedPendingPayment`); sent by notification cron worker; **skipped/cancelled** if order is paid/captured before send (`OrderEmailContext::shouldSendPendingPaymentEmail`; cancelled on `orderPaymentSuccess`) |
| `payment.success` | email | `payment.success.email` | `payment.success:order:{orderId}:email` | `PaymentService::completePayment()` after non-idempotent capture (Cashfree + Razorpay webhooks / verify); enqueue then immediate `claimAndProcessByIdempotencyKey` (outside DB txn); outcomes in `delivery_email_logs` |
| `payment.failed` | email | `payment.failed.email` | `payment.failed:order:{orderNumber}:email` | `PaymentService::markPaymentFailed()` when order moves to `payment_failed` (not per-attempt when retry remains open) |
| `order.status` | email | `order.status.email` | `order.status:order:{orderId}:{status}:email` | `AdminOrderService::updateStatus()` on real transition; `completePayment()` → confirmed |
| `order.payment_timeout_cancelled` | email | `order.payment_timeout_cancelled.email` | `order.payment_timeout_cancelled:order:{orderId}:email` | `UnpaidOrderExpiryService::processOrder()` after `OrderCancellationService::cancelForPaymentTimeout()` (not duplicated with `order.status`) |
| `refund.initiated` | email | `refund.initiated.email` | `refund.initiated:{requestId}:email` | `RefundRequestService` transition to `refund_initiated` |
| `refund.completed` | email | `refund.completed.email` | `refund.completed:{requestId}:email` | `RefundCompletionService::completeOrderRefund()` (webhook/cron/manual fetch); idempotent enqueue + immediate send |
| `support.ticket_created` | in_app | `support.ticket_created.in_app` | `support.ticket_created:{ticketId}:in_app` | `SupportTicketService::create()` after insert |
| `support.ticket_created` | email | `support.ticket_created.email` | `support.ticket_created:{ticketId}:email` | Same (if customer has valid email) |
| `support.ticket_replied` | email | `support.ticket_replied.email` | `support.ticket_replied:{ticketId}:email:{Y-m-d}` | `SupportTicketService::adminReply()` |
| `refund.status_updated` | email | `refund.status_updated.email` | `refund.status_updated:{requestId}:{status}:email` | `RefundRequestService` private `transition()` after successful status change |

## Intentionally deferred (not enqueued)

| Area | Reason |
|------|--------|
| Customer login OTP | Must stay **synchronous** (`OtpSmsService`); auth flow and rate limits depend on immediate provider result |
| Pending payment reminders | No repeat emails while payment stays pending (abandon ≠ failed) |
| D3 delivery SMS (`order_confirmed`, `ready`, `dispatched`, …) | **`DeliverySmsService`** + `delivery_sms_logs` + send locks — do not duplicate |
| Subscription lifecycle in-app | **`SubscriptionNotificationDedupeService`** + direct `CustomerNotificationService` |
| Subscription delivery / basket events | Existing dedupe and notify paths unchanged |
| Review moderation outcomes | No enqueue call sites |
| Payment webhook-only paths without `orderPaymentSuccess` | Only wired where `PaymentService` completes checkout |

## Direct paths that must not be duplicated

- **`OtpSmsService`** — login/register OTP SMS.
- **`DeliverySmsService::sendDeliveryEvent`** — transactional delivery SMS (D3).
- **`SubscriptionNotificationDedupeService::notifyOnce`** — subscription in-app dedupe.
- **`CustomerNotificationService::notify`** when called directly from subscription or other legacy flows.

Adding a queued copy of any of the above would risk double notifications unless idempotency is proven end-to-end.

## Delivery semantics

- Queue idempotency prevents duplicate **rows** for the same `(channel, idempotency_key)`.
- External SMTP/SMS delivery remains **at-least-once** if a provider accepts a message and the worker dies before marking `sent` (see `NotificationQueueService` class docblock).
