# Current telemetry collector

Go SQL writer for Current's optional Redis job telemetry. See [setup, support boundaries and failure behavior](../docs/ASYNC-TELEMETRY.md).

```sh
go test -race ./...
go build -trimpath -o current-collector .
```

Linux + CGO required. Run through `php artisan current:collect`; the wrapper supplies configuration over stdin. Local source evaluation can supply a private JSON configuration file instead. Never expose Redis or SQL credentials on the command line. Normal stdout contains lifecycle notices only; failures use fixed diagnostic categories without payloads or SQL values.
