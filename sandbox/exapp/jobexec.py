#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""jobexec.py COMMAND …: runs a document job's command under a seccomp filter.

The ExApp's runner (RUNNER_BACKEND=process) starts each command as the job's own
user without capabilities and without new privileges; this adds the filter before
exec, inherited by every child: no IPv4, IPv6 or packet sockets (so no network,
while unix sockets for LibreOffice stay), no io_uring (it can open sockets too), no
ptrace or reading other processes' memory, no mount, namespaces or keyrings, no BPF
or kernel modules. Refused calls fail with EPERM."""
import errno
import os
import sys

import seccomp

AF_INET, AF_INET6, AF_PACKET = 2, 10, 17
DENIED = ["ptrace", "process_vm_readv", "process_vm_writev", "mount", "umount2", "pivot_root", "chroot", "unshare", "setns",
          "keyctl", "add_key", "request_key", "bpf", "perf_event_open", "init_module", "finit_module", "delete_module",
          "kexec_load", "kexec_file_load", "io_uring_setup", "io_uring_enter", "io_uring_register", "userfaultfd",
          "open_by_handle_at", "name_to_handle_at", "fanotify_init", "quotactl", "swapon", "swapoff", "reboot", "acct"]

f = seccomp.SyscallFilter(defaction=seccomp.ALLOW)
for family in (AF_INET, AF_INET6, AF_PACKET):
    f.add_rule(seccomp.ERRNO(errno.EPERM), "socket", seccomp.Arg(0, seccomp.EQ, family))
    f.add_rule(seccomp.ERRNO(errno.EPERM), "socketpair", seccomp.Arg(0, seccomp.EQ, family))
for name in DENIED:
    try:
        f.add_rule(seccomp.ERRNO(errno.EPERM), name)
    except Exception:
        pass  # not on this architecture
f.load()
os.execvp(sys.argv[1], sys.argv[1:])
