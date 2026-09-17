#define _GNU_SOURCE

#include <errno.h>
#include <fcntl.h>
#include <inttypes.h>
#include <stdint.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

static int fail(const char *message)
{
    fprintf(stderr, "%s\n", message);

    return 1;
}

static int parse_identifier(const char *value, uintmax_t *result)
{
    char *end = NULL;
    errno = 0;
    *result = strtoumax(value, &end, 10);

    return errno == 0 && end != value && *end == '\0';
}

static int open_verified_root(const char *path, uintmax_t expected_device, uintmax_t expected_inode)
{
    if (path[0] != '/') {
        return -1;
    }

    int directory_fd = open("/", O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    char *copy = strdup(path);
    char *state = NULL;
    char *segment = copy == NULL ? NULL : strtok_r(copy, "/", &state);

    while (directory_fd >= 0 && segment != NULL) {
        int child_fd = openat(directory_fd, segment, O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
        close(directory_fd);
        directory_fd = child_fd;
        segment = strtok_r(NULL, "/", &state);
    }

    free(copy);
    struct stat root_stat;

    if (directory_fd < 0) {
        return -1;
    }

    if (fstat(directory_fd, &root_stat) != 0
        || !S_ISDIR(root_stat.st_mode)
        || (uintmax_t) root_stat.st_dev != expected_device
        || (uintmax_t) root_stat.st_ino != expected_inode) {
        close(directory_fd);

        return -1;
    }

    return directory_fd;
}

static volatile sig_atomic_t interrupted = 0;

static void handle_signal(int signal_number)
{
    (void) signal_number;
    interrupted = 1;
}

static int write_archive(const char *root, const char *archive_key, const char *source_path,
    uintmax_t expected_device, uintmax_t expected_inode)
{
    struct sigaction action = {0};
    action.sa_handler = handle_signal;
    sigemptyset(&action.sa_mask);
    sigaction(SIGTERM, &action, NULL);
    sigaction(SIGINT, &action, NULL);
    sigaction(SIGHUP, &action, NULL);

    int source_fd = open(source_path, O_RDONLY | O_NOFOLLOW | O_CLOEXEC);
    struct stat source_stat;

    if (source_fd < 0 || fstat(source_fd, &source_stat) != 0 || !S_ISREG(source_stat.st_mode)) {
        if (source_fd >= 0) {
            close(source_fd);
        }

        return fail("Local backup upload source must be a regular file.");
    }

    int directory_fd = open_verified_root(root, expected_device, expected_inode);

    if (directory_fd < 0) {
        close(source_fd);

        return fail("Local archive path changed while it was being opened.");
    }

    char *key = strdup(archive_key);
    char *state = NULL;
    char *segment = key == NULL ? NULL : strtok_r(key, "/", &state);

    if (segment == NULL) {
        free(key);
        close(directory_fd);
        close(source_fd);

        return fail("Invalid local backup target path.");
    }

    while (1) {
        char *next = strtok_r(NULL, "/", &state);

        if (strcmp(segment, ".") == 0 || strcmp(segment, "..") == 0) {
            free(key);
            close(directory_fd);
            close(source_fd);

            return fail("Invalid local backup target path.");
        }

        if (next == NULL) {
            break;
        }

        int child_fd = openat(directory_fd, segment, O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);

        if (child_fd < 0 && errno == ENOENT) {
            if (mkdirat(directory_fd, segment, 0700) != 0 && errno != EEXIST) {
                free(key);
                close(directory_fd);
                close(source_fd);

                return fail("Unable to create the local backup directory.");
            }

            child_fd = openat(directory_fd, segment, O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
        }

        if (child_fd < 0) {
            free(key);
            close(directory_fd);
            close(source_fd);

            return fail("Local backup target must remain within the archive path.");
        }

        close(directory_fd);
        directory_fd = child_fd;
        segment = next;
    }

    char temporary_name[128];
    int target_fd = -1;

    for (unsigned int attempt = 0; attempt < 100 && target_fd < 0; attempt++) {
        snprintf(temporary_name, sizeof(temporary_name), ".volumevault-upload-%jd-%u.tmp", (intmax_t) getpid(), attempt);
        target_fd = openat(directory_fd, temporary_name, O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, 0600);
    }

    if (target_fd < 0) {
        free(key);
        close(directory_fd);
        close(source_fd);

        return fail("Unable to create a staging file for the local backup.");
    }

    char buffer[1024 * 1024];
    int result = 0;

    while (!interrupted) {
        ssize_t bytes_read = read(source_fd, buffer, sizeof(buffer));

        if (bytes_read < 0 && errno == EINTR) {
            continue;
        }

        if (bytes_read <= 0) {
            if (bytes_read < 0) {
                result = fail("Unable to read the local backup upload source.");
            }

            break;
        }

        for (ssize_t offset = 0; offset < bytes_read;) {
            ssize_t bytes_written = write(target_fd, buffer + offset, (size_t) (bytes_read - offset));

            if (bytes_written < 0 && errno == EINTR) {
                continue;
            }

            if (bytes_written <= 0) {
                result = fail("Unable to write the local backup file.");
                break;
            }

            offset += bytes_written;
        }

        if (result != 0) {
            break;
        }
    }

    if (interrupted) {
        result = fail("Local backup upload was interrupted.");
    }

    if (result == 0 && !interrupted && fsync(target_fd) != 0) {
        result = fail("Unable to write the local backup file.");
    }

    if (interrupted && result == 0) {
        result = fail("Local backup upload was interrupted.");
    }

    close(target_fd);

    sigset_t publication_signals;
    sigset_t previous_signals;
    sigemptyset(&publication_signals);
    sigaddset(&publication_signals, SIGTERM);
    sigaddset(&publication_signals, SIGINT);
    sigaddset(&publication_signals, SIGHUP);
    sigprocmask(SIG_BLOCK, &publication_signals, &previous_signals);

    if (interrupted && result == 0) {
        result = fail("Local backup upload was interrupted.");
    }

    if (result == 0 && renameat(directory_fd, temporary_name, directory_fd, segment) != 0) {
        result = fail("Unable to publish the local backup file.");
    }

    sigprocmask(SIG_SETMASK, &previous_signals, NULL);

    if (result != 0) {
        unlinkat(directory_fd, temporary_name, 0);
    }

    free(key);
    close(directory_fd);
    close(source_fd);

    return result;
}

int main(int argc, char **argv)
{
    int write_mode = argc == 7 && strcmp(argv[1], "write") == 0;

    if (argc != 6 && !write_mode) {
        return fail("Invalid secure local archive reader arguments.");
    }

    int root_index = write_mode ? 2 : 1;
    int key_index = write_mode ? 3 : 2;
    int device_index = write_mode ? 5 : 4;
    int inode_index = write_mode ? 6 : 5;

    uintmax_t expected_device;
    uintmax_t expected_inode;

    if (!parse_identifier(argv[device_index], &expected_device) || !parse_identifier(argv[inode_index], &expected_inode)) {
        return fail("Invalid secure local archive root identity.");
    }

    if (write_mode) {
        return write_archive(argv[root_index], argv[key_index], argv[4], expected_device, expected_inode);
    }

    int directory_fd = open_verified_root(argv[root_index], expected_device, expected_inode);

    if (directory_fd < 0) {
        return fail("Local archive path changed while it was being opened.");
    }

    char *key = strdup(argv[key_index]);

    if (key == NULL) {
        close(directory_fd);

        return fail("Unable to allocate secure local archive path.");
    }

    char *state = NULL;
    char *segment = strtok_r(key, "/", &state);

    if (segment == NULL) {
        free(key);
        close(directory_fd);

        return fail("Local backup file does not exist.");
    }

    while (segment != NULL) {
        char *next = strtok_r(NULL, "/", &state);
        int flags = O_RDONLY | O_NOFOLLOW | O_CLOEXEC;

        if (next != NULL) {
            flags |= O_DIRECTORY;
        } else {
            flags |= O_NONBLOCK;
        }

        int child_fd = openat(directory_fd, segment, flags);
        close(directory_fd);

        if (child_fd < 0) {
            free(key);

            return fail(errno == ENOENT
                ? "Local backup file does not exist."
                : "Local backup file must be a regular file within the archive path.");
        }

        directory_fd = child_fd;
        segment = next;
    }

    free(key);

    struct stat source_stat;

    if (fstat(directory_fd, &source_stat) != 0 || !S_ISREG(source_stat.st_mode)) {
        close(directory_fd);

        return fail("Local backup file must be a regular file within the archive path.");
    }

    struct stat target_stat;

    if (stat(argv[3], &target_stat) == 0) {
        if (source_stat.st_dev == target_stat.st_dev && source_stat.st_ino == target_stat.st_ino) {
            close(directory_fd);

            return fail("Local backup source and target must be different files.");
        }
    } else if (errno != ENOENT) {
        close(directory_fd);

        return fail("Unable to inspect the local backup target.");
    }

    if (source_stat.st_nlink != 1) {
        close(directory_fd);

        return fail("Local backup file must not be linked outside the archive path.");
    }

    char buffer[1024 * 1024];
    uintmax_t total_bytes = 0;

    while (1) {
        ssize_t bytes_read = read(directory_fd, buffer, sizeof(buffer));

        if (bytes_read < 0) {
            if (errno == EINTR) {
                continue;
            }

            close(directory_fd);

            return fail("Unable to stream the local backup file.");
        }

        if (bytes_read == 0) {
            break;
        }

        total_bytes += (uintmax_t) bytes_read;

        ssize_t offset = 0;

        while (offset < bytes_read) {
            ssize_t bytes_written = write(STDOUT_FILENO, buffer + offset, (size_t) (bytes_read - offset));

            if (bytes_written < 0) {
                if (errno == EINTR) {
                    continue;
                }

                close(directory_fd);

                return fail("Unable to stream the local backup file.");
            }

            if (bytes_written == 0) {
                close(directory_fd);

                return fail("Unable to stream the local backup file.");
            }

            offset += bytes_written;
        }
    }

    if (total_bytes != (uintmax_t) source_stat.st_size) {
        close(directory_fd);

        return fail("Unable to stream the local backup file.");
    }

    close(directory_fd);

    return 0;
}
