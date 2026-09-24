# Test pyramid illustration

```text
    / E2E \
   /Integration\
  /    Unit     \
```

70% unit / 20% integration / 10% E2E is one illustrative mix. It is not an acceptance gate.
Choose boundaries and counts from failure risk, existing coverage, runtime, and maintenance.
A representative integration check may provide stronger evidence than many mocked unit cases.
