build: 
	docker build --no-cache -t jimanx2/telescope-otel .

push:
	docker push jimanx2/telescope-otel

all: build