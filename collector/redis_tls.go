package main

import (
	"crypto/tls"
	"crypto/x509"
	"errors"
	"io"
	"os"
	"strings"
)

// RedisTLS contains file references handed over privately by Laravel. Peer and
// hostname verification are always enabled; no insecure option exists here.
type RedisTLS struct {
	CA         string `json:"ca"`
	Cert       string `json:"cert"`
	Key        string `json:"key"`
	ServerName string `json:"server_name"`
}

func redisTLS(enabled bool, settings RedisTLS) (*tls.Config, error) {
	if !enabled {
		if settings != (RedisTLS{}) {
			return nil, errors.New("Redis TLS options require TLS transport")
		}
		return nil, nil
	}
	if len(settings.ServerName) > 253 || strings.ContainsAny(settings.ServerName, "\x00\r\n") {
		return nil, errors.New("Redis TLS server name is invalid")
	}
	config := &tls.Config{MinVersion: tls.VersionTLS12, ServerName: settings.ServerName}
	if settings.CA != "" {
		roots, err := x509.SystemCertPool()
		if err != nil {
			roots = x509.NewCertPool()
		}
		pem, err := redisTLSFile(settings.CA)
		if err != nil || !roots.AppendCertsFromPEM(pem) {
			return nil, errors.New("Redis TLS CA unavailable or invalid")
		}
		config.RootCAs = roots
	}
	if (settings.Cert == "") != (settings.Key == "") {
		return nil, errors.New("Redis TLS requires both client certificate and key")
	}
	if settings.Cert != "" {
		cert, certErr := redisTLSFile(settings.Cert)
		key, keyErr := redisTLSFile(settings.Key)
		if certErr != nil || keyErr != nil {
			return nil, errors.New("Redis TLS client certificate unavailable or invalid")
		}
		pair, err := tls.X509KeyPair(cert, key)
		if err != nil {
			return nil, errors.New("Redis TLS client certificate unavailable or invalid")
		}
		config.Certificates = []tls.Certificate{pair}
	}
	return config, nil
}

func redisTLSFile(path string) ([]byte, error) {
	if len(path) > 4096 || strings.ContainsRune(path, 0) {
		return nil, errors.New("invalid TLS file")
	}
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	info, err := file.Stat()
	if err != nil || !info.Mode().IsRegular() || info.Size() > 1048576 {
		return nil, errors.New("invalid TLS file")
	}
	value, err := io.ReadAll(io.LimitReader(file, 1048577))
	if err != nil || len(value) > 1048576 {
		return nil, errors.New("invalid TLS file")
	}
	return value, nil
}
