<?php

// The data classes that used to live here (RegisterRequest, OperationResult, OrderDetails, OrderSummary, OrderPage,
// ActionStatus) each have their own file now: Paymenter's autoloader only finds one class per file named after it,
// and a class stored in a shared file was "not found" in production while the tests (which preload this file) passed.
// The file itself is kept only because the committed vendor autoloader still lists it under "files".
