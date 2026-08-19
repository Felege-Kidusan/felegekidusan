"""Mirrors api/v1/core/app_release.php version compare."""


def compare_version(a: str, b: str) -> int:
    def parts(v: str):
        cleaned = "".join(ch if ch.isdigit() or ch == "." else "" for ch in v)
        nums = [int(p) if p else 0 for p in cleaned.split(".")]
        while len(nums) < 3:
            nums.append(0)
        return nums[:3]

    pa, pb = parts(a), parts(b)
    for x, y in zip(pa, pb):
        if x < y:
            return -1
        if x > y:
            return 1
    return 0


def test_order():
    assert compare_version("1.0.0", "1.1.0") == -1
    assert compare_version("1.1.0", "1.0.9") == 1
    assert compare_version("1.1.0", "1.1.0") == 0
    assert compare_version("2.0", "1.9.9") == 1


if __name__ == "__main__":
    test_order()
    print("version compare ok")
