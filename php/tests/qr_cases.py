import json, sys, segno
out = []
for text in ["otpauth://totp/LMD%20Finance%20Ltd:someone%40example.com?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=LMD%20Finance%20Ltd", "hello", "x" * 150]:
    for mask in range(8):
        q = segno.make(text, error="m", mode="byte", mask=mask, boost_error=False, micro=False)
        rows = ["".join("1" if c else "0" for c in row) for row in q.matrix]
        out.append({"text": text, "mask": mask, "rows": rows, "version": q.version})
json.dump(out, open(sys.argv[1], "w"))

