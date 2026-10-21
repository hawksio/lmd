"""Production entry point:  gunicorn -c deploy/gunicorn.conf.py wsgi:app"""
from backend import create_app

app = create_app()
