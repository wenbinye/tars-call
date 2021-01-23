
Health check:

    tars-call <server> --check|-c --address|-a <address>

Call function:

    tars-call <server>.<method> <params> --address|-a <address>
    tars-call --data|-d call.json --address|-a <address>

call.json:

```json
{
  "servant": "<server>",
  "method": "<method>",
  "params": {
    "<param1>": "<value1>"
  }
}

```
