"""Local development server: http://localhost:4321  (the site, /account and /admin)."""
import os

from backend import create_app

app = create_app()

if __name__ == "__main__":
    app.run(host="127.0.0.1", port=int(os.environ.get("PORT", 4321)), debug=False)
