# QuerySearchProcessor
Craft more complex eloquent searches based on query data passed in from http request parameters

## Overview
The goal of this package is to provide a simple way to craft hierarchical searches over more complicated Eloquent model
relationship hierarchies from your browser, while also staying out of the way of the traditional builder system. In
other words, this package will not expose the builder it's crafted to allow for extending.

## Testing
To run test suite, run the following:
```bash
 php vendor/bin/phpunit tests/Unit/QuerySearchProcessorTest.php
```