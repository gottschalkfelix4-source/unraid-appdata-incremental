#!/usr/bin/env python3
"""Minimal docker CLI mock for tests. State in $MOCK_DOCKER_STATE (JSON list of inspect objects)."""
import json, os, sys

state_file = os.environ["MOCK_DOCKER_STATE"]
log_file = os.environ.get("MOCK_DOCKER_LOG")
fail_stop = set(filter(None, os.environ.get("MOCK_DOCKER_FAIL_STOP", "").split(",")))

with open(state_file) as f:
    cts = json.load(f)

def save():
    with open(state_file, "w") as f:
        json.dump(cts, f, indent=1)

def find(ref):
    for c in cts:
        if c["Id"] == ref or c["Name"].lstrip("/") == ref:
            return c
    return None

args = sys.argv[1:]
if log_file:
    with open(log_file, "a") as f:
        f.write(" ".join(args) + "\n")

if args[:1] == ["ps"]:
    for c in cts:
        print(c["Id"])
elif args[:1] == ["inspect"]:
    rest = args[1:]
    fmt = None
    if rest[:1] == ["--format"]:
        fmt, rest = rest[1], rest[2:]
    res = [find(r) for r in rest]
    if any(r is None for r in res):
        sys.stderr.write("Error: No such object\n"); sys.exit(1)
    if fmt == "{{.State.Running}}":
        for r in res:
            print("true" if r["State"]["Running"] else "false")
    else:
        print(json.dumps(res, indent=1))
elif args[:1] in (["stop"], ["start"]):
    name = args[-1]
    c = find(name)
    if c is None:
        sys.stderr.write("Error: No such container\n"); sys.exit(1)
    if args[0] == "stop" and name in fail_stop:
        sys.stderr.write("Error: timeout stopping container\n"); sys.exit(1)
    c["State"]["Running"] = args[0] == "start"
    c["State"]["Status"] = "running" if args[0] == "start" else "exited"
    save()
    print(name)
else:
    sys.stderr.write("mock: unsupported %r\n" % args); sys.exit(2)
