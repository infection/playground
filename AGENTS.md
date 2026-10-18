# Agent guidelines

## Tests

- A test that expects an exception also checks its message: pair `expectException()` with `expectExceptionMessage()`, so the test fails when another exception of the same class is thrown.
