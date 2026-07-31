FROM python:3.13-slim@sha256:6771159cd4fa5d9bba1258caf0b82e6b73458c694d178ad97c5e925c2d0e1a91

WORKDIR /workspace/spikes/yfinance-provider

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY probe.py test_probe.py ./

ENTRYPOINT ["python", "probe.py"]
