# Behavioral test examples

```typescript
test("user can check out with a valid cart", async () => {
  const cart = createCart();
  cart.add(product);
  const result = await checkout(cart, sandboxPaymentMethod);
  expect(result.status).toBe("confirmed");
});
```

The tested seam reaches the behavior and uses isolated/fake external effects. Prefer a stable
public/component interface over a private helper's shape. Internal refactoring should not
invalidate unchanged behavior.

Persistence can be the actual contract: asserting stored state, transaction rollback, or an
outbox row is valid when a public read path cannot establish the invariant. Similarly, verify
a charge occurred only once when retry idempotency is the required behavior. These assertions
are not automatically implementation coupling.

A useful regression test fails on the reported defect. A test that only checks a mock's own
return value, a setup failure, or an unrelated happy path provides no such evidence.
