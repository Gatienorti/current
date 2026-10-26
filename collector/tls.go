package main

import (
	"crypto/tls"
	"crypto/x509"
	"errors"
	"os"
)

type SQLTLS struct {
	Enabled bool   `json:"enabled"`
	CA      string `json:"ca"`
	Cert    string `json:"cert"`
	Key     string `json:"key"`
}

func databaseTLS(settings SQLTLS, host string) (*tls.Config, error) {
	config := &tls.Config{MinVersion: tls.VersionTLS12, ServerName: host}
	if settings.CA != "" {
		roots, err := x509.SystemCertPool()
		if err != nil {
			roots = x509.NewCertPool()
		}
		pem, err := os.ReadFile(settings.CA)
		if err != nil || !roots.AppendCertsFromPEM(pem) {
			return nil, errors.New("SQL TLS CA unavailable or invalid")
		}
		config.RootCAs = roots
	}
	if (settings.Cert == "") != (settings.Key == "") {
		return nil, errors.New("SQL TLS requires both client certificate and key")
	}
	if settings.Cert != "" {
		pair, err := tls.LoadX509KeyPair(settings.Cert, settings.Key)
		if err != nil {
			return nil, errors.New("SQL TLS client certificate unavailable or invalid")
		}
		config.Certificates = []tls.Certificate{pair}
	}
	return config, nil
}
